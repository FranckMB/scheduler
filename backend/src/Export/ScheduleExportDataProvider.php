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
        $trainingCriteria = ['clubId' => $clubId, 'seasonId' => $seasonId];
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
}
