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
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use ReflectionClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
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

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // P4-67 — les contraintes d'unicité en base (Competition, TeamTag…)
            // transforment un doublon en erreur SQL : la nommer en 409 plutôt
            // que de laisser fuir un 500 (le doublon vient toujours du client —
            // double-clic, re-soumission).
            throw new ConflictHttpException('Cet élément existe déjà (doublon).');
        }
        $this->afterPersist($entity);

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

        $this->assertNotStale($entity);

        $this->updateEntityFromInput($entity, $input);
        $this->entityManager->flush();
        $this->afterPersist($entity);

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

    /**
     * Hook post-flush (POST/PUT) — no-op par défaut. Permet à un processor de réagir à un
     * effet de bord une fois l'entité persistée (ex. recaler un nom dérivé d'une donnée qui
     * arrive dans une requête voisine). Ne jamais y placer de logique métier obligatoire.
     */
    protected function afterPersist(object $entity): void {}

    /**
     * P4-25 — le verrou optimiste, câblé une fois pour toutes.
     *
     * ⚑ Le défaut : `processPut` remplaçait tous les champs éditables sans jamais regarder
     * la colonne `version`. Deux onglets sur la même ligne, ou un cache react-query périmé
     * de 30 s, et la seconde écriture écrase la première — **sans un mot**. Le premier
     * éditeur ne l'apprend pas, le second ne sait pas qu'il a effacé quelque chose. Constaté
     * sur `CoachWish`, mais universel : 30 entités sur 49 portent `#[ORM\Version]`.
     *
     * ⚠ Le mécanisme Doctrine EXISTAIT déjà — la colonne est mappée `#[ORM\Version]` partout.
     * Il n'était simplement jamais engagé : `processPut` recharge l'entité juste avant
     * d'écrire, donc la version concorde toujours au flush. Une protection présente et
     * inerte, ce qui est pire qu'absente : on croit l'avoir.
     *
     * **`If-Match` plutôt qu'un champ de payload**, et c'est un choix de coût autant que de
     * standard : un en-tête se lit en UN endroit, un champ aurait demandé de toucher les 30
     * DTO d'entrée. Son absence conserve exactement le comportement actuel — aucune écriture
     * existante ne casse, la protection s'active client par client, au rythme du front.
     */
    private function assertNotStale(object $entity): void
    {
        $ifMatch = $this->requestStack->getCurrentRequest()?->headers->get('If-Match');
        if (null === $ifMatch || '' === trim($ifMatch)) {
            return;
        }

        $expected = filter_var(trim($ifMatch, " \t\n\r\0\x0B\"'W/"), \FILTER_VALIDATE_INT);
        if (false === $expected) {
            throw new UnprocessableEntityHttpException('If-Match doit porter le numéro de version de la ressource.');
        }

        try {
            $this->entityManager->lock($entity, LockMode::OPTIMISTIC, $expected);
        } catch (OptimisticLockException) {
            // 409 et pas 412 : le client n'a rien à re-négocier, il a une version périmée
            // d'une ressource qui a bougé. Le message dit quoi faire — recharger — parce
            // qu'un « conflit » sans conduite à tenir renvoie le gestionnaire à lui-même.
            throw new ConflictHttpException('Cette fiche a été modifiée entre-temps par quelqu\'un d\'autre. Rechargez la page avant d\'enregistrer, sinon vous écraseriez sa saisie.');
        }
    }
}
