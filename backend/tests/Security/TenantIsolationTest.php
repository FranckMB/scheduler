<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Phase 1 skeleton — will be enriched in Phase 2 with real entities.
 *
 * Tests tenant isolation at 3 levels:
 * 1. API level: user from club A cannot access club B resources
 * 2. Repository level: queries are filtered by club_id
 * 3. SQL level: RLS prevents direct SQL access across clubs
 */
final class TenantIsolationTest extends TestCase
{
    /** @group phase1 */
    public function testApiIsolation(): void
    {
        // Phase 2: make authenticated request as club A to access club B resource
        // Expected: 404 (never 200)
        self::markTestSkipped('Phase 2: requires real entities and authentication');
    }

    /** @group phase1 */
    public function testRepositoryIsolation(): void
    {
        // Phase 2: TeamRepository::find() with club B context from club A
        // Expected: null
        self::markTestSkipped('Phase 2: requires real entities and TenantFilterListener');
    }

    /** @group phase1 */
    public function testSqlIsolation(): void
    {
        // Phase 2: PDO direct SELECT with SET LOCAL app.club_id = A
        // querying table with club_id = B
        // Expected: 0 rows (RLS blocks)
        self::markTestSkipped('Phase 2: requires real entities and RLS policies');
    }
}
