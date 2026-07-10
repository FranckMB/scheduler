<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Message\GenerateScheduleMessage;
use App\Service\GenerationComplexityGuard;
use App\Service\ManagementAccessGuard;
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
 * planning-versions (décision 6): the plain "Régénérer" creates a NEW linear
 * version (V2, V3…) with the CURRENT club structure — never regenerates a
 * version in place (that would silently overwrite it, so no new version appears).
 * The version's HARD-locked slots are carried over so the solver re-pins them,
 * exactly as the in-place regeneration used to. Contrast RegenerateFromVersion,
 * which RESTORES a past version's structure photo first.
 */
#[AsController]
final class RegenerateController extends AbstractController implements SeasonScopedWriteInterface
{
    use ResolvesCurrentClubTrait;

    private const IN_FLIGHT = [ScheduleStatus::PENDING, ScheduleStatus::GENERATING];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly GenerationComplexityGuard $complexityGuard,
    ) {}

    #[Route('/api/schedules/{id}/regenerate', name: 'api_schedule_regenerate', methods: ['POST'])]
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

        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (null !== $currentClubId && $source->getClubId() !== $currentClubId) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        // Season plans only — an overlay is regenerated via its own cockpit flow.
        if (null !== $source->getCalendarEntryId()) {
            return $this->json(['error' => 'Un overlay de période se régénère depuis le cockpit.'], Response::HTTP_CONFLICT);
        }
        // Only an already-generated version yields a new version: a VALIDATED plan
        // is read-only (reopen first), ARCHIVED is never resurrected, and a
        // DRAFT/in-flight has no version to branch from (the first generation is
        // the wizard's in-place path).
        if (!\in_array($source->getStatus(), [ScheduleStatus::COMPLETED, ScheduleStatus::FAILED], true)) {
            return $this->json(['error' => 'Seule une version terminée peut être régénérée (rouvrez un planning validé d\'abord).'], Response::HTTP_CONFLICT);
        }
        // Never branch while any version of the season is still solving.
        $inFlight = $this->entityManager->getRepository(Schedule::class)->count([
            'clubId' => $source->getClubId(),
            'seasonId' => $source->getSeasonId(),
            'status' => self::IN_FLIGHT,
        ]);
        if ($inFlight > 0) {
            return $this->json(['error' => 'Une génération est déjà en cours — attendez sa fin.'], Response::HTTP_CONFLICT);
        }

        // A10: reject an over-complex problem before queuing (same caps as the
        // normal generation path — a direct dispatch would otherwise bypass it).
        $violation = $this->complexityGuard->firstViolation($source->getClubId(), $source->getSeasonId());
        if (null !== $violation) {
            return $this->json([
                'error' => \sprintf('Génération bloquée : trop de %s (%d, limite %d).', $violation['cap'], $violation['count'], $violation['limit']),
                'cap' => $violation['cap'], 'count' => $violation['count'], 'limit' => $violation['limit'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // New PENDING version + carried-over HARD locks commit together, then we
        // dispatch (a dispatch failure only strands a PENDING the watchdog
        // reconciles — same trade-off as GenerateScheduleController).
        $newSchedule = (new Schedule)
            ->setClubId($source->getClubId())
            ->setSeasonId($source->getSeasonId())
            ->setName('Planning ' . (new DateTimeImmutable)->format('Y-m-d H:i'))
            ->setStatus(ScheduleStatus::PENDING);
        $this->entityManager->wrapInTransaction(function () use ($source, $newSchedule): void {
            $this->entityManager->persist($newSchedule);
            $this->carryOverHardLocks($source, $newSchedule->getId());
            $this->entityManager->flush();
        });

        $this->messageBus->dispatch(new GenerateScheduleMessage(
            scheduleId: $newSchedule->getId(),
            clubId: $newSchedule->getClubId(),
        ));

        return $this->json(['id' => $newSchedule->getId(), 'status' => ScheduleStatus::PENDING->value], Response::HTTP_ACCEPTED);
    }

    /** Clone the source version's HARD-locked slots onto the new version so the
     *  solver re-pins them (durable locks only — session temporary locks drop). */
    private function carryOverHardLocks(Schedule $source, string $newScheduleId): void
    {
        $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $source->getId()]);
        foreach ($slots as $slot) {
            if (LockLevel::HARD !== $slot->getLockLevel()) {
                continue;
            }
            $clone = (new ScheduleSlotTemplate)
                ->setClubId($slot->getClubId())
                ->setSeasonId($slot->getSeasonId())
                ->setScheduleId($newScheduleId)
                ->setTeamId($slot->getTeamId())
                ->setVenueId($slot->getVenueId())
                ->setCoachId($slot->getCoachId())
                ->setDayOfWeek($slot->getDayOfWeek())
                ->setStartTime($slot->getStartTime())
                ->setDurationMinutes($slot->getDurationMinutes())
                ->setLockLevel(LockLevel::HARD);
            $this->entityManager->persist($clone);
        }
    }
}
