<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\TenantOwnedInterface;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Service\AuditTrail;
use App\Service\EntityCascadeDeleter;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @template TEntity of object
 * @template TInput of object
 * @template TOutput of object
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
abstract class AbstractStateProcessor implements ProcessorInterface
{
    /**
     * Set via #[Required] (not the constructor) so the four cascade-delete
     * subclasses share it without every processor rewriting the base ctor.
     */
    protected ?EntityCascadeDeleter $cascadeDeleter = null;

    protected ?AuditTrail $auditTrail = null;

    protected ?Security $actorSecurity = null;

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly RequestStack $requestStack,
        protected readonly SeasonResolver $seasonResolver,
        protected readonly SeasonAccessGuard $seasonAccessGuard,
        protected readonly ManagementAccessGuard $managementAccessGuard,
    ) {}

    #[Required]
    public function setCascadeDeleter(EntityCascadeDeleter $cascadeDeleter): void
    {
        $this->cascadeDeleter = $cascadeDeleter;
    }

    #[Required]
    public function setAuditTrail(AuditTrail $auditTrail): void
    {
        $this->auditTrail = $auditTrail;
    }

    #[Required]
    public function setActorSecurity(Security $actorSecurity): void
    {
        $this->actorSecurity = $actorSecurity;
    }

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

        // BCK-09: a PUT must NEVER migrate an existing row to the request's current
        // season — that silently moves data across seasons (same club). Only stamp a
        // season if the entity somehow has none; never override its own.
        if (method_exists($entity, 'setSeasonId') && method_exists($entity, 'getSeasonId') && null === $entity->getSeasonId()) {
            $resolvedSeasonId = $this->resolveSeasonId($clubId, $seasonId);
            if (null !== $resolvedSeasonId) {
                $entity->setSeasonId($resolvedSeasonId);
            }
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

        // Entities carry no ORM/DB cascade — a subclass may purge the deleted
        // row's logical children (reservations, coach links, constraints…) here
        // so a bare delete never orphans them. No-op by default.
        $this->cascadeBeforeDelete($entity);

        $this->entityManager->remove($entity);
        $this->entityManager->flush();

        // RGPD audit trail : toute suppression API d'entité passe ici — un seul
        // point d'écriture couvre coach/équipe/gymnase/planning/contrainte….
        $actor = $this->actorSecurity?->getUser();
        $this->auditTrail?->record(
            AuditAction::ENTITY_DELETED,
            $actor instanceof User ? $actor->getId() : null,
            $clubId,
            new ReflectionClass($this->getEntityClass())->getShortName(),
            \is_string($id) ? $id : null,
        );
    }

    /** Purge the logical children of $entity before it is removed. No-op unless overridden. */
    protected function cascadeBeforeDelete(object $entity): void {}
}
