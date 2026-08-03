<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use App\ApiResource\SchedulePlanResource;
use App\Entity\SchedulePlan;
use App\Enum\SchedulePlanType;
use App\Repository\ScheduleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @extends AbstractStateProvider<SchedulePlan, SchedulePlanResource>
 */
class SchedulePlanStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        Pagination $pagination,
        private readonly ScheduleRepository $scheduleRepository,
    ) {
        parent::__construct($entityManager, $requestStack, $pagination);
    }

    /**
     * Enrichit le(s) DTO avec `hasVersions` en UNE requête groupée (patron
     * `TeamStateProvider::isEngaged`) — sans quoi le client tire toute la
     * collection des schedules pour répondre à un booléen (P4-23).
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $result = parent::provide($operation, $uriVariables, $context);
        if (null === $result) {
            return null;
        }

        /** @var iterable<SchedulePlanResource> $dtos */
        $dtos = $result instanceof SchedulePlanResource ? [$result] : $result;
        $ids = [];
        foreach ($dtos as $dto) {
            $ids[] = $dto->id;
        }
        if ([] === $ids) {
            return $result;
        }

        $withVersions = $this->scheduleRepository->planIdsWithVersions($ids);
        foreach ($dtos as $dto) {
            $dto->hasVersions = isset($withVersions[$dto->id]);
        }

        return $result;
    }

    protected function getEntityClass(): string
    {
        return SchedulePlan::class;
    }

    /**
     * @param SchedulePlan $entity
     */
    protected function mapEntityToOutput(object $entity): SchedulePlanResource
    {
        return SchedulePlanResource::fromEntity($entity);
    }

    /**
     * Club scoping = tenant_filter (SchedulePlan owns club_id); season scoping =
     * season_filter (owns season_id), so the collection is already the active
     * season's plans — no seasonId param (it would AND-collide with the filter).
     * Here we only narrow by period and type within that season.
     */
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return false;
        }

        $calendarEntryId = $this->uuidQueryParam($request, 'calendarEntryId');
        if (null !== $calendarEntryId) {
            $qb->andWhere('e.calendarEntryId = :calendarEntryId')->setParameter('calendarEntryId', $calendarEntryId);
        }

        $type = $request->query->get('type');
        if (\is_string($type) && '' !== $type) {
            $typeEnum = SchedulePlanType::tryFrom($type);
            if (null === $typeEnum) {
                throw new BadRequestHttpException(\sprintf('Invalid SchedulePlan type "%s".', $type));
            }
            $qb->andWhere('e.type = :type')->setParameter('type', $typeEnum);
        }

        return false;
    }
}
