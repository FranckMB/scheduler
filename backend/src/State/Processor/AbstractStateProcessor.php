<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\TenantOwnedInterface;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
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
        protected readonly SeasonResolver $seasonResolver,
        protected readonly SeasonAccessGuard $seasonAccessGuard,
        protected readonly ManagementAccessGuard $managementAccessGuard,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');
        $seasonId = $request?->attributes->get('_season_id') ?? $request?->headers->get('X-Season-Id');

        // SEC-07 — before the season guard so 403 wins over 409 (Import idiom).
        if ($this->requiresManagementRole()) {
            $this->managementAccessGuard->assertManager();
        }

        // Archived-season writes are refused (409). Only season-scoped entities
        // are gated — Club/User/Season carry no seasonId and stay editable.
        if (method_exists($this->getEntityClass(), 'setSeasonId')) {
            $this->seasonAccessGuard->assertWritable($request);
        }

        if ($operation instanceof DeleteOperationInterface) {
            $this->processDelete($uriVariables, $clubId);

            return null;
        }

        $method = $operation instanceof HttpOperation ? $operation->getMethod() : '';
        if ('POST' === $method) {
            return $this->processPost($data, $clubId, $seasonId);
        }

        if (\in_array($method, ['PUT', 'PATCH'], true)) {
            return $this->processPut($data, $uriVariables, $clubId, $seasonId);
        }

        return $data;
    }

    /**
     * SEC-07: processors whose writes are management-sensitive (cockpit surface)
     * override this to true — every POST/PUT/PATCH/DELETE then requires an
     * owner/admin membership. Opt-in so wizard-entity processors keep their
     * current semantics until the coach-role permission model is designed.
     */
    protected function requiresManagementRole(): bool
    {
        return false;
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

    protected function resolveSeasonId(?string $clubId, ?string $seasonId): ?string
    {
        if (null !== $seasonId) {
            return $seasonId;
        }

        if (null === $clubId) {
            return null;
        }

        // Fallback when the listener set no _season_id (e.g. non-HTTP context):
        // the calendar-derived current season, same rule as the listener.
        $season = $this->seasonResolver->currentSeason($clubId);

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

        if (null !== $clubId && $entity instanceof TenantOwnedInterface) {
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

        if (null !== $clubId && $entity instanceof TenantOwnedInterface && $entity->getClubId() !== $clubId) {
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

        if (null !== $clubId && $entity instanceof TenantOwnedInterface && $entity->getClubId() !== $clubId) {
            throw new AccessDeniedHttpException('Access denied');
        }

        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }
}
