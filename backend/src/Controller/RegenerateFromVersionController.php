<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Enum\ScheduleStatus;
use App\Message\GenerateScheduleMessage;
use App\Service\GenerationComplexityGuard;
use App\Service\ManagementAccessGuard;
use App\Service\StructureRestorer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * planning-versions D3: regenerate the season plan UNDER THE CONDITIONS of an
 * existing version — restore that version's structure photo (D2), then launch a
 * fresh generation → a new linear version (V4, V5…). The current structure is
 * replaced (the client confirms the impact first). Overlays and versions with
 * no photo (pre-D2) are refused.
 */
#[AsController]
final class RegenerateFromVersionController extends AbstractController implements SeasonScopedWriteInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly StructureRestorer $structureRestorer,
    ) {}

    private const IN_FLIGHT = [ScheduleStatus::PENDING, ScheduleStatus::GENERATING];

    #[Route('/api/schedules/{id}/regenerate-from', name: 'api_schedule_regenerate_from', methods: ['POST'])]
    public function __invoke(string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        try {
            $source = $this->entityManager->getRepository(Schedule::class)->find($id);
        } catch (Throwable) {
            $source = null;
        }

        if (!$source instanceof Schedule) {
            return $this->json(['error' => 'Schedule not found.'], Response::HTTP_NOT_FOUND);
        }

        $currentClubId = $this->resolveCurrentClubId();
        if (null !== $currentClubId && $source->getClubId() !== $currentClubId) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        // Season plans only — an overlay carries no restorable club structure.
        if (null !== $source->getCalendarEntryId()) {
            return $this->json(['error' => 'Only a season version can be regenerated from.'], Response::HTTP_CONFLICT);
        }

        // Only a FINISHED, non-locked version's conditions can be replayed: a
        // VALIDATED plan is read-only (D1 model archives its siblings), an
        // ARCHIVED one is never resurrected, and a DRAFT/FAILED/in-flight one
        // has no meaningful structure to restore.
        if (ScheduleStatus::COMPLETED !== $source->getStatus()) {
            return $this->json(['error' => 'Seule une version terminée peut être régénérée (rouvrez un planning validé d\'abord).'], Response::HTTP_CONFLICT);
        }

        // The restore wipes the club structure — refuse while ANY version of the
        // season is still solving, or its import would land slots referencing
        // teams/venues the wipe just deleted (the ClubGenerationLock only
        // serialises solves, it does not guard this destructive write).
        $inFlight = $this->entityManager->getRepository(Schedule::class)->count([
            'clubId' => $source->getClubId(),
            'seasonId' => $source->getSeasonId(),
            'status' => self::IN_FLIGHT,
        ]);
        if ($inFlight > 0) {
            return $this->json(['error' => 'Une génération est en cours — attendez sa fin avant de régénérer aux conditions d\'une version.'], Response::HTTP_CONFLICT);
        }

        // Read the photo (409 if none) and reject an over-complex restored
        // problem BEFORE any destructive change (A10, same caps as the normal
        // generation path — the direct dispatch would otherwise bypass it).
        $data = $this->structureRestorer->readSnapshot($source);
        $violation = GenerationComplexityGuard::evaluate(
            teams: \count($data['Team'] ?? []),
            venues: \count($data['Venue'] ?? []),
            coaches: \count($data['Coach'] ?? []),
            slots: \count($data['VenueTrainingSlot'] ?? []),
            constraints: \count($data['Constraint'] ?? []),
        );
        if (null !== $violation) {
            return $this->json([
                'error' => \sprintf('Génération bloquée : trop de %s (%d, limite %d).', $violation['cap'], $violation['count'], $violation['limit']),
                'cap' => $violation['cap'], 'count' => $violation['count'], 'limit' => $violation['limit'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // ATOMIC: the destructive restore + the new PENDING version commit
        // together — a failure never leaves the structure wiped with no version
        // to show for it. PENDING (not DRAFT) so the frontend polls it and it
        // is guarded in-flight (no concurrent delete/validate).
        $newSchedule = (new Schedule)
            ->setClubId($source->getClubId())
            ->setSeasonId($source->getSeasonId())
            ->setName('Planning ' . (new DateTimeImmutable)->format('Y-m-d H:i'))
            ->setStatus(ScheduleStatus::PENDING);
        $this->entityManager->wrapInTransaction(function () use ($source, $data, $newSchedule): void {
            $this->structureRestorer->apply($source->getClubId(), $source->getSeasonId(), $data);
            $this->entityManager->persist($newSchedule);
            $this->entityManager->flush();
        });

        // After commit: a dispatch failure only strands a PENDING schedule the
        // stuck-schedule watchdog reconciles — the structure is already safely
        // restored with its version (same trade-off as GenerateScheduleController).
        $this->messageBus->dispatch(new GenerateScheduleMessage(
            scheduleId: $newSchedule->getId(),
            clubId: $newSchedule->getClubId(),
        ));

        return $this->json(['id' => $newSchedule->getId(), 'status' => ScheduleStatus::PENDING->value], Response::HTTP_ACCEPTED);
    }

    private function resolveCurrentClubId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        $clubId = $request?->attributes->get('_club_id');
        if (\is_string($clubId) && '' !== $clubId) {
            return $clubId;
        }

        $clubId = $request?->headers->get('X-Club-Id');
        if (\is_string($clubId) && '' !== $clubId) {
            return $clubId;
        }

        return null;
    }
}
