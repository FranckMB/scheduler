<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\State\Pagination\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

abstract class AbstractStateProvider implements ProviderInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly RequestStack $requestStack,
        protected readonly Pagination $pagination,
    ) {
    }

    abstract protected function getEntityClass(): string;
    abstract protected function mapEntityToOutput(object $entity): object;

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');

        if ($operation instanceof \ApiPlatform\Metadata\GetCollection) {
            return $this->provideCollection($operation, $context, $clubId);
        }

        return $this->provideItem($uriVariables, $clubId);
    }

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

        if ($clubId !== null && method_exists($entity, 'getClubId') && $entity->getClubId() !== $clubId) {
            return null;
        }

        return $this->mapEntityToOutput($entity);
    }
}