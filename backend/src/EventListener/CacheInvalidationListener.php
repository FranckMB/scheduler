<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\ScheduleConstraintBuilder;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Purges Redis cache keys prefixed with club:{club_id}: when business entities
 * are modified. This ensures schedule snapshots and tenant data stay fresh.
 *
 * Phase 1 stub: listens for all entities; in Phase 2 it will target only
 * Venue, Coach, Team, Schedule and related entities.
 */
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postRemove)]
class CacheInvalidationListener
{
    /** @var array<string, string[]> club_id => [cache_keys] */
    private array $pendingInvalidations = [];

    public function __construct(
        private readonly CacheItemPoolInterface $tenantCachePool,
        // Tag-aware : le payload solveur est purgé par TAG (cf. flushInvalidations).
        private readonly TagAwareAdapterInterface $scheduleCachePool,
    ) {}

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->collectInvalidation($args->getObject());
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->collectInvalidation($args->getObject());
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->collectInvalidation($args->getObject());
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->flushInvalidations();
    }

    /**
     * Flush collected invalidations. Called on kernel.terminate to avoid
     * purging cache during the same request that might still read from it.
     */
    public function flushInvalidations(): void
    {
        foreach ($this->pendingInvalidations as $clubId => $keys) {
            foreach ($keys as $key) {
                $this->tenantCachePool->deleteItem($key);
                $this->scheduleCachePool->deleteItem($key);
            }
            // Le payload solveur : purgé par TAG, toutes saisons du club d'un coup.
            // Indispensable — `SportCategory` et `TeamTag` alimentent le payload mais ne
            // portent PAS de `seasonId`, donc aucune clé par saison ne serait devinable
            // depuis l'entité éditée.
            $this->scheduleCachePool->invalidateTags([ScheduleConstraintBuilder::cacheTag((string) $clubId)]);
        }

        $this->pendingInvalidations = [];
    }

    private function collectInvalidation(object $entity): void
    {
        $clubId = $this->resolveClubId($entity);
        if (null === $clubId) {
            return;
        }

        // P2-9ter — `schedule_input` a QUITTÉ cette liste : sa clé porte désormais la
        // saison (`ScheduleConstraintBuilder::cacheKey`), donc une clé écrite à la main
        // ici ne viserait plus rien. Il est purgé par TAG dans flushInvalidations(), ce
        // qui supprime la possibilité même de désynchroniser les deux côtés.
        $this->pendingInvalidations[$clubId] = [
            \sprintf('club.%s.tenant_data', $clubId),
            \sprintf('club.%s.schedule_snapshot', $clubId),
        ];
    }

    private function resolveClubId(object $entity): ?string
    {
        $method = 'getClubId';
        if (!method_exists($entity, $method)) {
            return null;
        }

        $clubId = $entity->$method();

        return \is_string($clubId) && '' !== $clubId ? $clubId : null;
    }
}
