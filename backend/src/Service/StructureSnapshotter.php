<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleStructureSnapshot;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamTagAssignment;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use BackedEnum;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * planning-versions D2: captures a FAITHFUL photo of the club structure at the
 * moment a season-plan version is generated, so the D3 restore ("Travailler
 * sur cette version") can rebuild the workspace exactly as it was.
 *
 * Schedule.snapshotData cannot serve this purpose: it is the transformed solver
 * payload (engine vocabulary — targetTag expanded, venue_closed rewritten…),
 * lossy for the backend entities (coach links, reservations, tiers).
 *
 * Serialization is GENERIC via Doctrine ClassMetadata (field name → column
 * value), so it stays faithful column by column and survives schema evolution
 * with zero per-entity code. Normalisation: DateTime → ATOM, BackedEnum →
 * value, arrays as-is.
 */
final class StructureSnapshotter
{
    /**
     * The structural families of a (club, season). Dated constraints and
     * period-overlay reservations belong to the CALENDAR, not the structure —
     * both are filtered to `calendarEntryId IS NULL`.
     */
    private const FAMILIES = [
        SportCategory::class,
        Team::class,
        Venue::class,
        VenueTrainingSlot::class,
        Coach::class,
        TeamCoach::class,
        CoachPlayerMembership::class,
        Constraint::class,
        Reservation::class,
        TeamTagAssignment::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /** Capture (or replace) the structure photo attached to this schedule version. */
    public function capture(Schedule $schedule): void
    {
        $clubId = $schedule->getClubId();
        $seasonId = $schedule->getSeasonId();

        $data = [];
        foreach (self::FAMILIES as $entityClass) {
            $data[$this->familyKey($entityClass)] = array_map(
                fn (object $row): array => $this->serializeRow($row),
                $this->loadFamily($entityClass, $clubId, $seasonId),
            );
        }

        $snapshot = $this->entityManager->getRepository(ScheduleStructureSnapshot::class)
            ->findOneBy(['scheduleId' => $schedule->getId()]);
        if (!$snapshot instanceof ScheduleStructureSnapshot) {
            $snapshot = (new ScheduleStructureSnapshot)
                ->setClubId($clubId)
                ->setSeasonId($seasonId)
                ->setScheduleId($schedule->getId());
            $this->entityManager->persist($snapshot);
        }
        $snapshot->setData($data);
        $this->entityManager->flush();
    }

    /**
     * @param class-string $entityClass
     *
     * @return list<object>
     */
    private function loadFamily(string $entityClass, string $clubId, string $seasonId): array
    {
        // SportCategory is club-scoped but season-less; TeamTagAssignment is
        // season-scoped but club-less (same special cases as SeasonDataPurger).
        $criteria = match ($entityClass) {
            SportCategory::class => ['clubId' => $clubId],
            TeamTagAssignment::class => ['seasonId' => $seasonId],
            default => ['clubId' => $clubId, 'seasonId' => $seasonId],
        };
        // Only the PERMANENT structure: dated rows belong to the calendar.
        if (Constraint::class === $entityClass || Reservation::class === $entityClass) {
            $criteria['calendarEntryId'] = null;
        }

        return $this->entityManager->getRepository($entityClass)->findBy($criteria);
    }

    /** @return array<string, mixed> */
    private function serializeRow(object $entity): array
    {
        $metadata = $this->entityManager->getClassMetadata($entity::class);
        $row = [];
        foreach ($metadata->getFieldNames() as $field) {
            $value = $metadata->getFieldValue($entity, $field);
            $row[$field] = match (true) {
                $value instanceof DateTimeInterface => $value->format(\DATE_ATOM),
                $value instanceof BackedEnum => $value->value,
                default => $value,
            };
        }

        return $row;
    }

    /** @param class-string $entityClass */
    private function familyKey(string $entityClass): string
    {
        $pos = strrpos($entityClass, '\\');

        return false === $pos ? $entityClass : substr($entityClass, $pos + 1);
    }
}
