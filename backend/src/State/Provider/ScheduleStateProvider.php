<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\ScheduleResource;
use App\Entity\Schedule;
use App\Entity\ScheduleStructureSnapshot;

/**
 * @extends AbstractStateProvider<Schedule, ScheduleResource>
 */
class ScheduleStateProvider extends AbstractStateProvider
{
    /**
     * Enrich the mapped DTO(s) with `hasStructurePhoto` (D3 gating) in ONE batch
     * query — a per-DTO EXISTS would N+1 the schedules collection. The photo
     * lookup is tenant-scoped by the Doctrine tenant_filter (ScheduleStructureSnapshot
     * owns a club_id), so it never crosses clubs.
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
        foreach ($dtos as $dto) {
            $dto->hasStructurePhoto = isset($withPhoto[$dto->id]);
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
        /** @var list<array{scheduleId: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('sss.scheduleId')
            ->from(ScheduleStructureSnapshot::class, 'sss')
            ->where('sss.scheduleId IN (:ids)')
            ->setParameter('ids', $scheduleIds)
            ->getQuery()
            ->getScalarResult();

        $set = [];
        foreach ($rows as $row) {
            $set[$row['scheduleId']] = true;
        }

        return $set;
    }
}
