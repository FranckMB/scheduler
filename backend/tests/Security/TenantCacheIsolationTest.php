<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('phase1')]
final class TenantCacheIsolationTest extends TestCase
{
    public function testCacheIsolation(): void
    {
        self::markTestSkipped('Cache isolation test deferred to Phase 2');
    }
}
