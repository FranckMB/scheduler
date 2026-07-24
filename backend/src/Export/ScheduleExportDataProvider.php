<?php

declare(strict_types=1);

namespace App\Export;

use App\Entity\Coach;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Service\SchedulePlanProvisioner;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Loads the data a schedule export needs: the slots (optionally scoped to a
 * single venue) plus the tenant-scoped team / venue / coach name maps. One home
 * for the queries both export generators used to duplicate.
 */
final class ScheduleExportDataProvider
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    public function load(Schedule $schedule, ?string $venueId = null): ScheduleExportData
    {
        $criteria = ['scheduleId' => $schedule->getId()];
        if (null !== $venueId) {
            // Scope the query itself instead of over-fetching then filtering in PHP.
            $criteria['venueId'] = $venueId;
        }
        $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy($criteria);

        $clubId = $schedule->getClubId();
        $seasonId = $schedule->getSeasonId();

        $categoryNames = [];
        foreach ($this->entityManager->getRepository(SportCategory::class)->findBy(['clubId' => $clubId]) as $category) {
            $categoryNames[$category->getId()] = $category->getName();
        }

        $teamNames = [];
        $teamCategories = [];
        foreach ($this->entityManager->getRepository(Team::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $team) {
            $teamNames[$team->getId()] = $team->getName();
            $teamCategories[$team->getId()] = $categoryNames[$team->getSportCategoryId()] ?? '';
        }

        $venues = [];
        foreach ($this->entityManager->getRepository(Venue::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $venue) {
            $venues[$venue->getId()] = ['name' => $venue->getName(), 'color' => $venue->getColor()];
        }

        $coachNames = [];
        foreach ($this->entityManager->getRepository(Coach::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $coach) {
            $coachNames[$coach->getId()] = trim(\sprintf('%s %s', $coach->getFirstName(), $coach->getLastName()));
        }

        // Defined-but-unfilled venue windows ("créneaux vides"): the same view the
        // on-screen grid shows. A window is "filled" as soon as ≥1 team is placed
        // on the same venue+day+start (capacity is not split — MVP, matches the UI).
        $filled = [];
        foreach ($slots as $slot) {
            $filled[$slot->getVenueId() . '|' . $slot->getDayOfWeek() . '|' . $slot->getStartTime()->format('H:i')] = true;
        }
        // #8 — LA COUCHE DE LA VERSION EXPORTÉE, et elle seule. Depuis que chaque période
        // possède sa grille (copie du modèle de saison à la naissance du plan), un club à
        // trois périodes a quatre exemplaires du même créneau en base : sans ce filtre,
        // l'export d'un planning quelconque affichait chaque créneau vide quatre fois.
        // Un plan de saison lit les créneaux du socle (schedulePlanId NULL), une période
        // les siens — exactement la règle que suit le payload envoyé à l'engine.
        $trainingCriteria = ['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => $this->slotLayerOf($schedule)];
        if (null !== $venueId) {
            $trainingCriteria['venueId'] = $venueId;
        }
        $emptySlots = [];
        foreach ($this->entityManager->getRepository(VenueTrainingSlot::class)->findBy($trainingCriteria) as $window) {
            $key = $window->getVenueId() . '|' . $window->getDayOfWeek() . '|' . $window->getStartTime()->format('H:i');
            if (!isset($filled[$key])) {
                $emptySlots[] = new ExportEmptyWindow($window->getVenueId(), $window->getDayOfWeek(), $window->getStartTime(), $window->getDurationMinutes());
            }
        }

        return new ScheduleExportData($slots, $teamNames, $teamCategories, $venues, $coachNames, $emptySlots);
    }

    /**
     * La couche de créneaux d'une version : NULL pour le socle (les créneaux de saison),
     * l'id du plan pour une période (sa grille à elle).
     */
    private function slotLayerOf(Schedule $schedule): ?string
    {
        return $this->schedulePlanProvisioner->isSeasonSchedule($schedule) ? null : $schedule->getSchedulePlanId();
    }
}
