<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Phase 1 skeleton — will be enriched in Phase 2 with real entities.
 *
 * Tests Redis cache isolation between clubs:
 * 1. Club A generates schedule → cache populated
 * 2. Club B reads schedule → cache B empty (no leak)
 * 3. Club A modifies coach → cache A purged, cache B intact
 * 4. Club B modifies venue → invalidation B, cache A intact
 */
final class TenantCacheIsolationTest extends TestCase
{
    /** @group phase1 */
    public function testClubAGeneratesScheduleCachePopulated(): void
    {
        self::markTestSkipped('Phase 2: requires real entities and Redis');
    }

    /** @group phase1 */
    public function testClubBReadsEmptyCache(): void
    {
        self::markTestSkipped('Phase 2: requires real entities and Redis');
    }

    /** @group phase1 */
    public function testClubAModifiesCoachCacheAPurgedCacheBIntact(): void
    {
        self::markTestSkipped('Phase 2: requires real entities and CacheInvalidationListener');
    }

    /** @group phase1 */
    public function testClubBModifiesVenueInvalidationBCacheAIntact(): void
    {
        self::markTestSkipped('Phase 2: requires real entities and CacheInvalidationListener');
    }
}
