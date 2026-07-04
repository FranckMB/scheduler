<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use App\Entity\TenantOwnedInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @template TEntity of object
 * @template TOutput of object
 *
 * @implements ProviderInterface<TOutput>
 */
abstract class AbstractStateProvider implements ProviderInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly RequestStack $requestStack,
        protected readonly Pagination $pagination,
    ) {}

    /**
     * @return TOutput|array<int, TOutput>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');

        if ($operation instanceof \ApiPlatform\Metadata\GetCollection) {
            return $this->provideCollection($operation, $context, $clubId);
        }

        return $this->provideItem($uriVariables, $clubId);
    }

    /**
     * @return class-string<TEntity>
     */
    abstract protected function getEntityClass(): string;

    /**
     * @param TEntity $entity
     *
     * @return TOutput
     */
    abstract protected function mapEntityToOutput(object $entity): object;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, TOutput>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityClass(), 'e');

        // Subclasses may bound the collection from request query params (e.g. ?scheduleId=).
        // A bounded result set (all rows of one parent) bypasses the default 30-item pagination.
        $bounded = $this->applyRequestFilters($qb);

        if (!$bounded && $this->pagination->isEnabled($operation, $context)) {
            $offset = $this->pagination->getOffset($operation, $context);
            $limit = $this->pagination->getLimit($operation, $context);
            $qb->setFirstResult($offset)
               ->setMaxResults($limit);
        }

        $query = $qb->getQuery();
        $results = $query->getResult();

        return array_map([$this, 'mapEntityToOutput'], $results);
    }

    /**
     * Hook: add WHERE clauses derived from the current request query.
     * Return true when the filter bounds the result to a single parent
     * (so pagination is skipped and every matching row is returned).
     *
     * For entities that own a club_id, tenant scoping is applied by the
     * Doctrine tenant_filter (TenantFilterListener). Entities WITHOUT a
     * club_id (Club, User) are NOT covered by that filter and must bound
     * their own collection here — see ClubStateProvider (SEC-01).
     */
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $uriVariables
     *
     * @return TOutput|null
     */
    protected function provideItem(array $uriVariables, ?string $clubId): ?object
    {
        $id = $uriVariables['id'] ?? null;
        if (!$id) {
            return null;
        }

        $entity = $this->entityManager->find($this->getEntityClass(), $id);
        if (!$entity) {
            return null;
        }

        if (null !== $clubId && $entity instanceof TenantOwnedInterface && $entity->getClubId() !== $clubId) {
            return null;
        }

        return $this->mapEntityToOutput($entity);
    }
}
