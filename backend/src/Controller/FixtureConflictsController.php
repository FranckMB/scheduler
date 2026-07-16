<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Fixture;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\TeamCoach;
use App\Repository\CalendarEntryRepository;
use App\Service\MatchConflictDetector;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * On-the-fly match/training conflict radar for a single coach (spec
 * gestion-matchs palier A, PR-2). Recomputed at each call from the current
 * fixtures + the schedule effective on each match date (period overlay, else
 * the season baseline) — nothing is persisted. Read-only display feed for the
 * future placement grid / radar.
 *
 * Tenant scope: everything is loaded through mapped-entity repositories, so the
 * Doctrine club+season filters apply automatically — a club only ever sees its
 * own conflicts (guarded by FixtureConflictsApiTest).
 */
final class FixtureConflictsController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CalendarEntryRepository $calendarEntryRepository,
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly MatchConflictDetector $detector,
        private readonly \App\Service\SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    // priority > 0: this static path must win over API Platform's /api/fixtures/{id}
    // item route, which would otherwise swallow "conflicts" as an (invalid) uuid.
    #[Route('/api/fixtures/conflicts', name: 'api_fixture_conflicts', methods: ['GET'], priority: 10)]
    public function __invoke(): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var list<Fixture> $fixtures */
        $fixtures = $this->entityManager->getRepository(Fixture::class)->findBy([]);
        /** @var list<TeamCoach> $teamCoachRows */
        $teamCoachRows = $this->entityManager->getRepository(TeamCoach::class)->findBy([]);

        $season = $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId);
        // ADR-0002 : le calendrier de base = la version CHOISIE du plan SEASON.
        // null = espace de travail : rien n'est arrêté, donc rien contre quoi
        // détecter un conflit.
        $seasonScheduleId = $this->schedulePlanProvisioner->chosenOfSeasonPlan($season?->getId());

        // Active period entries capture the dates they cover: inside them the base
        // plan does not apply (user rule "soit un overlay généré, soit le planning
        // de base" — and a closure with no overlay means "no training"). Ordered
        // so overlapping periods resolve deterministically.
        $activePeriods = [];
        $scheduleIds = null !== $seasonScheduleId ? [$seasonScheduleId] : [];
        foreach ($this->calendarEntryRepository->findActivePeriodsOrdered() as $period) {
            $overlayId = $period->getOverlayScheduleId();
            $activePeriods[] = [
                'start' => $period->getStartDate(),
                'end' => $period->getEndDate(),
                'scheduleId' => $overlayId,
            ];
            if (null !== $overlayId) {
                $scheduleIds[] = $overlayId;
            }
        }

        $slotsBySchedule = [];
        if ([] !== $scheduleIds) {
            /** @var list<ScheduleSlotTemplate> $slots */
            $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
                'scheduleId' => array_values(array_unique($scheduleIds)),
            ]);
            foreach ($slots as $slot) {
                $slotsBySchedule[$slot->getScheduleId()][] = $slot;
            }
        }

        $conflicts = $this->detector->detect($fixtures, $teamCoachRows, $seasonScheduleId, $activePeriods, $slotsBySchedule);

        return $this->json([
            'clubId' => $clubId,
            'seasonId' => $season?->getId(),
            'conflicts' => $conflicts,
            // Même raison que le radar des périodes : sans version pointée, la saison
            // n'a pas de calendrier, `MATCH_TRAINING` ne peut rien détecter hors période,
            // et `conflicts: []` devient indiscernable d'une saison réellement saine. Le
            // gestionnaire poserait un match sur un entraînement vivant. Un silence qui
            // ment est pire qu'un blanc.
            'seasonPlanChosen' => null !== $seasonScheduleId,
        ]);
    }
}
