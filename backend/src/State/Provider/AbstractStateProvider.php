<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
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

        if ($this->pagination->isEnabled($operation, $context)) {
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

        if (null !== $clubId && method_exists($entity, 'getClubId') && $entity->getClubId() !== $clubId) {
            return null;
        }

        return $this->mapEntityToOutput($entity);
    }
}
