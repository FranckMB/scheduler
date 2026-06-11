<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\SeasonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @template TEntity of object
 * @template TInput of object
 * @template TOutput of object
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
abstract class AbstractStateProcessor implements ProcessorInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly RequestStack $requestStack,
        protected readonly SeasonRepository $seasonRepository,
    ) {
    }

    /**
     * @return class-string<TEntity>
     */
    abstract protected function getEntityClass(): string;

    /**
     * @param TInput $input
     *
     * @return TEntity
     */
    abstract protected function createEntityFromInput(object $input): object;

    /**
     * @param TEntity $entity
     * @param TInput  $input
     */
    abstract protected function updateEntityFromInput(object $entity, object $input): void;

    /**
     * @param TEntity $entity
     *
     * @return TOutput
     */
    abstract protected function mapEntityToOutput(object $entity): object;

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');
        $seasonId = $request?->attributes->get('_season_id') ?? $request?->headers->get('X-Season-Id');

        if ($operation instanceof DeleteOperationInterface) {
            $this->processDelete($uriVariables, $clubId);

            return null;
        }

        $method = $operation instanceof HttpOperation ? $operation->getMethod() : '';
        if ('POST' === $method) {
            return $this->processPost($data, $clubId, $seasonId);
        }

        if (in_array($method, ['PUT', 'PATCH'], true)) {
            return $this->processPut($data, $uriVariables, $clubId, $seasonId);
        }

        return $data;
    }

    protected function resolveSeasonId(?string $clubId, ?string $seasonId): ?string
    {
        if (null !== $seasonId) {
            return $seasonId;
        }

        if (null === $clubId) {
            return null;
        }

        $season = $this->seasonRepository->findActiveByClubId($clubId);

        return $season?->getId();
    }

    /**
     * @param TInput $input
     *
     * @return TOutput
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        $entity = $this->createEntityFromInput($input);
        $resolvedSeasonId = $this->resolveSeasonId($clubId, $seasonId);

        if (null !== $clubId && method_exists($entity, 'setClubId')) {
            $entity->setClubId($clubId);
        }
        if (null !== $resolvedSeasonId && method_exists($entity, 'setSeasonId')) {
            $entity->setSeasonId($resolvedSeasonId);
        }

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $this->mapEntityToOutput($entity);
    }

    /**
     * @param TInput               $input
     * @param array<string, mixed> $uriVariables
     *
     * @return TOutput
     */
    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        $id = $uriVariables['id'] ?? null;
        $entity = $this->entityManager->find($this->getEntityClass(), $id);

        if (!$entity) {
            throw new NotFoundHttpException('Resource not found');
        }

        if (null !== $clubId && method_exists($entity, 'getClubId') && $entity->getClubId() !== $clubId) {
            throw new AccessDeniedHttpException('Access denied');
        }

        $resolvedSeasonId = $this->resolveSeasonId($clubId, $seasonId);
        if (null !== $resolvedSeasonId && method_exists($entity, 'setSeasonId')) {
            $entity->setSeasonId($resolvedSeasonId);
        }

        $this->updateEntityFromInput($entity, $input);
        $this->entityManager->flush();

        return $this->mapEntityToOutput($entity);
    }

    /** @param array<string, mixed> $uriVariables */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        $id = $uriVariables['id'] ?? null;
        $entity = $this->entityManager->find($this->getEntityClass(), $id);

        if (!$entity) {
            throw new NotFoundHttpException('Resource not found');
        }

        if (null !== $clubId && method_exists($entity, 'getClubId') && $entity->getClubId() !== $clubId) {
            throw new AccessDeniedHttpException('Access denied');
        }

        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }
}
