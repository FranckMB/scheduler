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
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * planning-versions D3: restore the club STRUCTURE to the exact state captured
 * when a version was generated (its ScheduleStructureSnapshot, D2), so the
 * manager can regenerate under those conditions. Season-scoped.
 *
 * Replaces the current permanent structure (teams/venues/slots/coaches/links/
 * permanent constraints/base reservations) with the photo — re-inserting each
 * row with its ORIGINAL id so the graph (reservations→team/venue, links,
 * dated constraints' scopeTargetId) stays consistent. NEVER touches the
 * schedules/versions themselves, nor the calendar (calendar entries, dated
 * constraints, overlay reservations) — those are not "structure".
 *
 * Symmetric to StructureSnapshotter::serializeRow (dates ATOM ↔ DateTimeImmutable,
 * enums value ↔ ::from).
 */
final class StructureRestorer
{
    use DisablesTenantFilters;

    /** Short family key → entity class (mirror of StructureSnapshotter::FAMILIES). */
    private const FAMILY_CLASS = [
        'SportCategory' => SportCategory::class,
        'Team' => Team::class,
        'Venue' => Venue::class,
        'VenueTrainingSlot' => VenueTrainingSlot::class,
        'Coach' => Coach::class,
        'TeamCoach' => TeamCoach::class,
        'CoachPlayerMembership' => CoachPlayerMembership::class,
        'Constraint' => Constraint::class,
        'Reservation' => Reservation::class,
        'TeamTagAssignment' => TeamTagAssignment::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * The version's captured structure photo (D2).
     *
     * @throws ConflictHttpException when the source has no photo (pre-D2)
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function readSnapshot(Schedule $source): array
    {
        $snapshot = $this->entityManager->getRepository(ScheduleStructureSnapshot::class)
            ->findOneBy(['scheduleId' => $source->getId()]);
        if (!$snapshot instanceof ScheduleStructureSnapshot) {
            throw new ConflictHttpException('Cette version n\'a pas de photo de structure (générée avant l\'historique) — impossible de restaurer ses conditions.');
        }

        return $snapshot->getData();
    }

    /**
     * Replace the current permanent structure with the photo. NO transaction of
     * its own — the caller wraps this AND the new-version creation in one
     * transaction so the destructive wipe is never committed without a version
     * to show for it.
     *
     * @param array<string, list<array<string, mixed>>> $data
     */
    public function apply(string $clubId, string $seasonId, array $data): void
    {
        $this->disableTenantFilters($this->entityManager);
        $this->wipeStructure($clubId, $seasonId);
        // Bulk DQL DELETE removes the rows but leaves the (now-stale) objects in
        // the identity map — re-inserting the SAME ids would raise an identity
        // collision. Detach everything first.
        $this->entityManager->clear();

        foreach (self::FAMILY_CLASS as $family => $entityClass) {
            foreach ($data[$family] ?? [] as $rowData) {
                $this->entityManager->persist($this->hydrate($entityClass, $rowData));
            }
        }
        $this->entityManager->flush();

        // A dated (calendar) constraint / overlay reservation created for an
        // entity that did NOT exist at the photo's moment now dangles (its
        // target is gone) — clean those ghosts, like the E1 cascade would.
        $this->purgeDanglingCalendarRefs($clubId, $seasonId);
        $this->entityManager->flush();
    }

    private function purgeDanglingCalendarRefs(string $clubId, string $seasonId): void
    {
        $tenant = ['c' => $clubId, 's' => $seasonId];

        // A dated Constraint whose scope target (team/coach/venue) is gone.
        $this->deleteDangling(
            'SELECT c.id FROM App\Entity\Constraint c WHERE c.clubId = :c AND c.seasonId = :s AND c.calendarEntryId IS NOT NULL AND ('
            . '(c.scope = :team AND NOT EXISTS (SELECT 1 FROM App\Entity\Team t WHERE t.id = c.scopeTargetId)) '
            . 'OR (c.scope = :coach AND NOT EXISTS (SELECT 1 FROM App\Entity\Coach co WHERE co.id = c.scopeTargetId)) '
            . 'OR (c.scope = :facility AND NOT EXISTS (SELECT 1 FROM App\Entity\Venue v WHERE v.id = c.scopeTargetId)))',
            $tenant + ['team' => \App\Enum\ConstraintScope::TEAM, 'coach' => \App\Enum\ConstraintScope::COACH, 'facility' => \App\Enum\ConstraintScope::FACILITY],
            'DELETE App\Entity\Constraint c WHERE c.id IN (:ids)',
        );

        // A dated Reservation whose team or venue is gone.
        $this->deleteDangling(
            'SELECT r.id FROM App\Entity\Reservation r WHERE r.clubId = :c AND r.seasonId = :s AND r.calendarEntryId IS NOT NULL AND ('
            . 'NOT EXISTS (SELECT 1 FROM App\Entity\Team t WHERE t.id = r.teamId) OR NOT EXISTS (SELECT 1 FROM App\Entity\Venue v WHERE v.id = r.venueId))',
            $tenant,
            'DELETE App\Entity\Reservation r WHERE r.id IN (:ids)',
        );

        // Period-editable structure: a period's team override / borrowed slot whose
        // target team/venue is gone after the restore — mirror the E1 cascade
        // (team/venue delete) on the restore path.
        $this->deleteDangling(
            'SELECT o.id FROM App\Entity\TeamPeriodOverride o WHERE o.clubId = :c AND o.seasonId = :s AND NOT EXISTS (SELECT 1 FROM App\Entity\Team t WHERE t.id = o.teamId)',
            $tenant,
            'DELETE App\Entity\TeamPeriodOverride o WHERE o.id IN (:ids)',
        );
        // A period's constraint toggle whose permanent constraint is gone after the restore.
        $this->deleteDangling(
            'SELECT o.id FROM App\Entity\ConstraintPeriodOverride o WHERE o.clubId = :c AND o.seasonId = :s AND NOT EXISTS (SELECT 1 FROM App\Entity\Constraint co WHERE co.id = o.constraintId)',
            $tenant,
            'DELETE App\Entity\ConstraintPeriodOverride o WHERE o.id IN (:ids)',
        );
        $this->deleteDangling(
            'SELECT vts.id FROM App\Entity\VenueTrainingSlot vts WHERE vts.clubId = :c AND vts.seasonId = :s AND vts.calendarEntryId IS NOT NULL AND NOT EXISTS (SELECT 1 FROM App\Entity\Venue v WHERE v.id = vts.venueId)',
            $tenant,
            'DELETE App\Entity\VenueTrainingSlot vts WHERE vts.id IN (:ids)',
        );
    }

    /**
     * Two-step ghost cleanup: resolve dangling ids via `$selectDql` (a correlated
     * DQL DELETE on the reserved-word `constraint` table emits invalid SQL — SELECT
     * supports the alias, so resolve ids first), then `DELETE … WHERE id IN`.
     *
     * @param array<string, mixed> $params
     */
    private function deleteDangling(string $selectDql, array $params, string $deleteDql): void
    {
        $query = $this->entityManager->createQuery($selectDql);
        foreach ($params as $key => $value) {
            $query->setParameter($key, $value);
        }
        $ids = $query->getSingleColumnResult();
        if ([] !== $ids) {
            $this->entityManager->createQuery($deleteDql)->setParameter('ids', $ids)->execute();
        }
    }

    /** Delete the current permanent structure (NOT schedules, NOT the calendar). */
    private function wipeStructure(string $clubId, string $seasonId): void
    {
        // TeamTagAssignment has a season_id but NO club_id (scoped by season).
        $this->deleteFamily(TeamCoach::class, $clubId, $seasonId);
        $this->deleteFamily(CoachPlayerMembership::class, $clubId, $seasonId);
        $this->deleteFamily(TeamTagAssignment::class, null, $seasonId);
        $this->deleteFamily(Reservation::class, $clubId, $seasonId, permanentOnly: true);
        $this->deleteFamily(Constraint::class, $clubId, $seasonId, permanentOnly: true);
        $this->deleteFamily(Team::class, $clubId, $seasonId);
        // permanentOnly: a period's own slots (calendarEntryId set) belong to the
        // calendar, not the base structure — a base-version restore must not wipe them.
        $this->deleteFamily(VenueTrainingSlot::class, $clubId, $seasonId, permanentOnly: true);
        $this->deleteFamily(Venue::class, $clubId, $seasonId);
        $this->deleteFamily(Coach::class, $clubId, $seasonId);
        $this->deleteFamily(SportCategory::class, $clubId, null);
    }

    /** @param class-string $entityClass */
    private function deleteFamily(string $entityClass, ?string $clubId, ?string $seasonId, bool $permanentOnly = false): void
    {
        $qb = $this->entityManager->createQueryBuilder()->delete($entityClass, 'e');
        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }
        if (null !== $seasonId) {
            $qb->andWhere('e.seasonId = :seasonId')->setParameter('seasonId', $seasonId);
        }
        if ($permanentOnly) {
            // Dated rows belong to the calendar (overlays / period closures) — kept.
            $qb->andWhere('e.calendarEntryId IS NULL');
        }
        $qb->getQuery()->execute();
    }

    /**
     * @param class-string         $entityClass
     * @param array<string, mixed> $rowData
     */
    private function hydrate(string $entityClass, array $rowData): object
    {
        $metadata = $this->entityManager->getClassMetadata($entityClass);
        /** @var object $entity */
        $entity = $metadata->getReflectionClass()->newInstanceWithoutConstructor();
        foreach ($metadata->getFieldNames() as $field) {
            if (!\array_key_exists($field, $rowData)) {
                continue;
            }
            $mapping = $metadata->getFieldMapping($field);
            $enumType = $mapping->enumType;
            $metadata->setFieldValue($entity, $field, $this->typedValue((string) $mapping->type, \is_string($enumType) ? $enumType : null, $rowData[$field]));
        }

        return $entity;
    }

    /** @param class-string<BackedEnum>|null $enumType */
    private function typedValue(string $type, ?string $enumType, mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }
        if (null !== $enumType) {
            return $enumType::from($value);
        }
        if (str_contains($type, 'date') || str_contains($type, 'time')) {
            return new DateTimeImmutable((string) $value);
        }

        return $value;
    }
}
