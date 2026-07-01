<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Team;
use App\EventListener\CacheInvalidationListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[Group('phase1')]
final class TenantCacheIsolationTest extends TestCase
{
    private const CACHE_SUFFIXES = ['schedule_input', 'tenant_data', 'schedule_snapshot'];

    /**
     * Modifying a club A entity must purge only club A's tenant/schedule cache
     * keys — club B's cached data must survive (no cross-tenant eviction).
     */
    public function testCacheInvalidationIsolatesClubs(): void
    {
        $tenantPool = new ArrayAdapter;
        $schedulePool = new ArrayAdapter;
        $listener = new CacheInvalidationListener($tenantPool, $schedulePool);

        $clubA = 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';
        $clubB = 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb';

        $this->seedClubCache($tenantPool, $clubA);
        $this->seedClubCache($tenantPool, $clubB);
        $this->seedClubCache($schedulePool, $clubA);
        $this->seedClubCache($schedulePool, $clubB);

        // A change on a club A entity is persisted -> collect + flush invalidations.
        $listener->postPersist(new PostPersistEventArgs(
            (new Team)->setClubId($clubA),
            $this->createMock(EntityManagerInterface::class),
        ));
        $listener->flushInvalidations();

        foreach ([$tenantPool, $schedulePool] as $pool) {
            foreach (self::CACHE_SUFFIXES as $suffix) {
                self::assertFalse(
                    $pool->hasItem(\sprintf('club.%s.%s', $clubA, $suffix)),
                    \sprintf('Club A cache key "%s" should be purged', $suffix),
                );
                self::assertTrue(
                    $pool->hasItem(\sprintf('club.%s.%s', $clubB, $suffix)),
                    \sprintf('Club B cache key "%s" must survive club A invalidation', $suffix),
                );
            }
        }
    }

    /**
     * An entity that cannot resolve a club id must not purge any tenant cache.
     */
    public function testEntityWithoutClubIdPurgesNothing(): void
    {
        $tenantPool = new ArrayAdapter;
        $schedulePool = new ArrayAdapter;
        $listener = new CacheInvalidationListener($tenantPool, $schedulePool);

        $item = $tenantPool->getItem('club.orphan.tenant_data');
        $item->set('kept');
        $tenantPool->save($item);

        $listener->postPersist(new PostPersistEventArgs(
            new stdClass,
            $this->createMock(EntityManagerInterface::class),
        ));
        $listener->flushInvalidations();

        self::assertTrue($tenantPool->hasItem('club.orphan.tenant_data'));
    }

    private function seedClubCache(ArrayAdapter $pool, string $clubId): void
    {
        foreach (self::CACHE_SUFFIXES as $suffix) {
            $item = $pool->getItem(\sprintf('club.%s.%s', $clubId, $suffix));
            $item->set('cached-' . $clubId);
            $pool->save($item);
        }
    }
}
