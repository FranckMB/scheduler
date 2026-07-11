<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\ScheduleResource;
use App\Entity\Schedule;
use App\Entity\ScheduleStructureSnapshot;
use App\Entity\Season;

/**
 * @extends AbstractStateProvider<Schedule, ScheduleResource>
 */
class ScheduleStateProvider extends AbstractStateProvider
{
    /**
     * Enrich the mapped DTO(s) with `hasStructurePhoto` (D3 gating) and
     * `isLiveContext` (★) in TWO batch queries — a per-DTO EXISTS would N+1 the
     * schedules collection. Both lookups are tenant-scoped by the Doctrine
     * tenant_filter (ScheduleStructureSnapshot / Season own a club_id), so they
     * never cross clubs.
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $result = parent::provide($operation, $uriVariables, $context);
        if (null === $result) {
            return null;
        }

        /** @var iterable<ScheduleResource> $dtos */
        $dtos = $result instanceof ScheduleResource ? [$result] : $result;
        $ids = [];
        foreach ($dtos as $dto) {
            $ids[] = $dto->id;
        }
        if ([] === $ids) {
            return $result;
        }

        $withPhoto = $this->scheduleIdsWithPhoto($ids);
        $liveContext = $this->liveContextScheduleIds($ids);
        foreach ($dtos as $dto) {
            $dto->hasStructurePhoto = isset($withPhoto[$dto->id]);
            $dto->isLiveContext = isset($liveContext[$dto->id]);
        }

        return $result;
    }

    protected function getEntityClass(): string
    {
        return Schedule::class;
    }

    /**
     * @param Schedule $entity
     */
    protected function mapEntityToOutput(object $entity): ScheduleResource
    {
        return ScheduleResource::fromEntity($entity);
    }

    /**
     * @param list<string> $scheduleIds
     *
     * @return array<string, true> set of schedule ids that own a structure photo
     */
    private function scheduleIdsWithPhoto(array $scheduleIds): array
    {
        return $this->idSetFromColumn(ScheduleStructureSnapshot::class, 'scheduleId', $scheduleIds);
    }

    /**
     * @param list<string> $scheduleIds
     *
     * @return array<string, true> set of schedule ids that ARE some season's loaded context (★)
     */
    private function liveContextScheduleIds(array $scheduleIds): array
    {
        return $this->idSetFromColumn(Season::class, 'liveContextScheduleId', $scheduleIds);
    }

    /**
     * The set of `$ids` that appear in `$entityClass.$column` — one tenant-scoped
     * batch query folded into a lookup set. (A NULL column value never matches an
     * IN (:ids) clause, so no null guard is needed.).
     *
     * @param class-string $entityClass
     * @param list<string> $ids
     *
     * @return array<string, true>
     */
    private function idSetFromColumn(string $entityClass, string $column, array $ids): array
    {
        /** @var list<array<string, string>> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('e.' . $column)
            ->from($entityClass, 'e')
            ->where('e.' . $column . ' IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getScalarResult();

        $set = [];
        foreach ($rows as $row) {
            $set[$row[$column]] = true;
        }

        return $set;
    }
}
