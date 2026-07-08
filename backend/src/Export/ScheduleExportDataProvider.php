<?php

declare(strict_types=1);

namespace App\Export;

use App\Entity\Coach;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\Venue;
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

        return new ScheduleExportData($slots, $teamNames, $teamCategories, $venues, $coachNames);
    }
}
