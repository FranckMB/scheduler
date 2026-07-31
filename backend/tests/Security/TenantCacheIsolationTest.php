<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Team;
use App\EventListener\CacheInvalidationListener;
use App\Service\ScheduleConstraintBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

#[Group('phase1')]
final class TenantCacheIsolationTest extends TestCase
{
    /**
     * Les clés purgées NOMMÉMENT par le listener. `schedule_input` n'en fait plus
     * partie (P2-9ter) : sa clé porte désormais la saison, il est purgé par TAG —
     * couvert par testScheduleInputIsPurgedByTagAcrossSeasons.
     */
    private const CACHE_SUFFIXES = ['tenant_data', 'schedule_snapshot'];

    /**
     * Modifying a club A entity must purge only club A's tenant/schedule cache
     * keys — club B's cached data must survive (no cross-tenant eviction).
     */
    public function testCacheInvalidationIsolatesClubs(): void
    {
        $tenantPool = new ArrayAdapter;
        $schedulePool = new TagAwareAdapter(new ArrayAdapter);
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
     * P2-9ter NR-4 — le payload solveur est purgé par TAG, sur TOUTES les saisons du
     * club, et sur le club édité SEULEMENT.
     *
     * Le piège que ce test ferme : la clé du payload porte maintenant la saison, mais
     * le listener ne connaît que le club — deux entités du payload (`SportCategory`,
     * `TeamTag`) n'ont même pas de `seasonId`. Reconstruire une clé à la main ici
     * cesserait donc de viser quoi que ce soit, EN SILENCE. En passant par
     * `ScheduleConstraintBuilder::cacheTag`, la clé et l'invalidation ont une source
     * unique : elles ne peuvent plus diverger.
     */
    public function testScheduleInputIsPurgedByTagAcrossSeasons(): void
    {
        $schedulePool = new TagAwareAdapter(new ArrayAdapter);
        $listener = new CacheInvalidationListener(new ArrayAdapter, $schedulePool);

        $clubA = 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';
        $clubB = 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb';
        $seasonOne = '11111111-1111-4111-8111-111111111111';
        $seasonTwo = '22222222-2222-4222-8222-222222222222';

        // Le club A a DEUX saisons en cache : la purge doit emporter les deux.
        foreach ([[$clubA, $seasonOne], [$clubA, $seasonTwo], [$clubB, $seasonOne]] as [$club, $season]) {
            $item = $schedulePool->getItem(ScheduleConstraintBuilder::cacheKey($club, $season));
            $item->set(['payload' => $club . $season]);
            $item->tag(ScheduleConstraintBuilder::cacheTag($club));
            $schedulePool->save($item);
        }

        $listener->postPersist(new PostPersistEventArgs(
            (new Team)->setClubId($clubA),
            $this->createMock(EntityManagerInterface::class),
        ));
        $listener->flushInvalidations();

        self::assertFalse(
            $schedulePool->hasItem(ScheduleConstraintBuilder::cacheKey($clubA, $seasonOne)),
            'le payload de la saison 1 du club édité doit être purgé',
        );
        self::assertFalse(
            $schedulePool->hasItem(ScheduleConstraintBuilder::cacheKey($clubA, $seasonTwo)),
            'purger par tag doit emporter TOUTES les saisons du club — une entité sans seasonId ne peut viser aucune clé',
        );
        self::assertTrue(
            $schedulePool->hasItem(ScheduleConstraintBuilder::cacheKey($clubB, $seasonOne)),
            'le payload d’un AUTRE club doit survivre',
        );
    }

    /**
     * An entity that cannot resolve a club id must not purge any tenant cache.
     */
    public function testEntityWithoutClubIdPurgesNothing(): void
    {
        $tenantPool = new ArrayAdapter;
        $schedulePool = new TagAwareAdapter(new ArrayAdapter);
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

    private function seedClubCache(ArrayAdapter|TagAwareAdapter $pool, string $clubId): void
    {
        foreach (self::CACHE_SUFFIXES as $suffix) {
            $item = $pool->getItem(\sprintf('club.%s.%s', $clubId, $suffix));
            $item->set('cached-' . $clubId);
            $pool->save($item);
        }
    }
}
