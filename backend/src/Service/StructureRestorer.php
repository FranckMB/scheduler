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

    /** @throws ConflictHttpException when the source version has no captured photo (pre-D2) */
    public function restore(Schedule $source): void
    {
        $snapshot = $this->entityManager->getRepository(ScheduleStructureSnapshot::class)
            ->findOneBy(['scheduleId' => $source->getId()]);
        if (!$snapshot instanceof ScheduleStructureSnapshot) {
            throw new ConflictHttpException('Cette version n\'a pas de photo de structure (générée avant l\'historique) — impossible de restaurer ses conditions.');
        }

        $clubId = $source->getClubId();
        $seasonId = $source->getSeasonId();
        $data = $snapshot->getData();

        $this->entityManager->wrapInTransaction(function () use ($clubId, $seasonId, $data): void {
            $this->disableTenantFilters($this->entityManager);
            $this->wipeStructure($clubId, $seasonId);
            // Bulk DQL DELETE removes the rows but leaves the (now-stale) objects
            // in the identity map — re-inserting the SAME ids would then raise an
            // identity collision. Detach everything first.
            $this->entityManager->clear();

            foreach (self::FAMILY_CLASS as $family => $entityClass) {
                foreach ($data[$family] ?? [] as $rowData) {
                    $this->entityManager->persist($this->hydrate($entityClass, $rowData));
                }
            }
            $this->entityManager->flush();
        });
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
        $this->deleteFamily(VenueTrainingSlot::class, $clubId, $seasonId);
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
