<?php

declare(strict_types=1);

namespace App\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Cache\CacheItemPoolInterface;

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
        private readonly CacheItemPoolInterface $scheduleCachePool,
    ) {
    }

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

    private function collectInvalidation(object $entity): void
    {
        $clubId = $this->resolveClubId($entity);
        if ($clubId === null) {
            return;
        }

        $this->pendingInvalidations[$clubId] = [
            sprintf('club:%s:schedule_input', $clubId),
            sprintf('club:%s:tenant_data', $clubId),
            sprintf('club:%s:schedule_snapshot', $clubId),
        ];
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
        }

        $this->pendingInvalidations = [];
    }

    private function resolveClubId(object $entity): ?string
    {
        $method = 'getClubId';
        if (!method_exists($entity, $method)) {
            return null;
        }

        $clubId = $entity->$method();

        return is_string($clubId) && $clubId !== '' ? $clubId : null;
    }
}
