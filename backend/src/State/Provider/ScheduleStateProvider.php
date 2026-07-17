<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\ScheduleResource;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleStructureSnapshot;
use App\Entity\Season;
use App\Enum\SchedulePlanType;

/**
 * @extends AbstractStateProvider<Schedule, ScheduleResource>
 */
class ScheduleStateProvider extends AbstractStateProvider
{
    /**
     * Enrich the mapped DTO(s) with `hasStructurePhoto` (D3 gating), `isLiveContext`
     * (★), `isChosen` (ADR-0002: its plan points at it) and `planType` (ADR-0002 C4:
     * SEASON = socle vs overlay) in FOUR batch queries — a per-DTO EXISTS would N+1
     * the schedules collection. All lookups are tenant-scoped by the Doctrine
     * tenant_filter (ScheduleStructureSnapshot / Season / SchedulePlan each own a
     * club_id), so they never cross clubs.
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
        $chosen = $this->chosenScheduleIds($ids);
        $planType = $this->planTypeByScheduleId($ids);
        foreach ($dtos as $dto) {
            $dto->hasStructurePhoto = isset($withPhoto[$dto->id]);
            $dto->isLiveContext = isset($liveContext[$dto->id]);
            $dto->isChosen = isset($chosen[$dto->id]);
            $dto->planType = $planType[$dto->id] ?? null;
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
     * @param list<string> $scheduleIds
     *
     * @return array<string, true> set of schedule ids their plan POINTS at (ADR-0002 inv. 1)
     */
    private function chosenScheduleIds(array $scheduleIds): array
    {
        return $this->idSetFromColumn(SchedulePlan::class, 'chosenScheduleId', $scheduleIds);
    }

    /**
     * ADR-0002 C4 : le type de plan de chaque version — SEASON | CLOSURE | HOLIDAY,
     * la seule vérité du « socle ? ». Un JOIN batché (Schedule.schedulePlanId →
     * SchedulePlan.type), tenant-scopé par le tenant_filter (les deux entités portent
     * un club_id), donc jamais cross-club. Une version sans plan (anomalie) est
     * absente du JOIN → planType null côté DTO.
     *
     * @param list<string> $scheduleIds
     *
     * @return array<string, string> scheduleId → type de plan
     */
    private function planTypeByScheduleId(array $scheduleIds): array
    {
        /** @var list<array{id: string, planType: string|SchedulePlanType}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('s.id AS id', 'p.type AS planType')
            ->from(Schedule::class, 's')
            ->innerJoin(SchedulePlan::class, 'p', 'WITH', 'p.id = s.schedulePlanId')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $scheduleIds)
            ->getQuery()
            ->getScalarResult();

        $map = [];
        foreach ($rows as $row) {
            // getScalarResult peut rendre l'enum OU sa valeur scalaire selon la voie
            // d'hydratation Doctrine — on normalise vers la valeur string du DTO.
            $type = $row['planType'];
            $map[$row['id']] = $type instanceof SchedulePlanType ? $type->value : $type;
        }

        return $map;
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
