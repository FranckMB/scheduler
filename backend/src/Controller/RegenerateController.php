<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Enum\ScheduleStatus;
use App\Message\GenerateScheduleMessage;
use App\Service\GenerationComplexityGuard;
use App\Service\ManagementAccessGuard;
use App\Service\SchedulePlanProvisioner;
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
 * HARD locks survive without any copy here: the generation payload
 * (ScheduleConstraintBuilder::findBaseSlotTemplates) already feeds every base
 * version's HARD slots as pins, so the solver re-honours them — exactly as the
 * in-place regeneration used to. Contrast RegenerateFromVersion, which RESTORES
 * a past version's structure photo first.
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
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
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

        // New PENDING version, then dispatch (a dispatch failure only strands a
        // PENDING the watchdog reconciles — same trade-off as
        // GenerateScheduleController). No slot copy: the generation payload
        // re-pins the base versions' HARD locks on its own.
        $newSchedule = (new Schedule)
            ->setClubId($source->getClubId())
            ->setSeasonId($source->getSeasonId())
            ->setName('Planning ' . (new DateTimeImmutable)->format('Y-m-d H:i'))
            ->setStatus(ScheduleStatus::PENDING);
        $this->entityManager->wrapInTransaction(function () use ($newSchedule): void {
            $this->entityManager->persist($newSchedule);
            // ADR-0002 Lot A: the new version joins the season's SchedulePlan.
            $this->schedulePlanProvisioner->linkSchedule($newSchedule);
            $this->entityManager->flush();
        });

        $this->messageBus->dispatch(new GenerateScheduleMessage(
            scheduleId: $newSchedule->getId(),
            clubId: $newSchedule->getClubId(),
        ));

        return $this->json(['id' => $newSchedule->getId(), 'status' => ScheduleStatus::PENDING->value], Response::HTTP_ACCEPTED);
    }
}
