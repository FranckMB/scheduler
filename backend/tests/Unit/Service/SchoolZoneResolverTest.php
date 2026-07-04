<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SchoolZoneResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('phase1')]
final class SchoolZoneResolverTest extends TestCase
{
    private SchoolZoneResolver $resolver;

    public function testResolveSingleDepartmentPerZone(): void
    {
        // Unambiguous codes embedding exactly one department.
        self::assertSame('A', $this->resolver->resolveFromFfbbCode('LR0069'));  // 69 Rhône
        self::assertSame('B', $this->resolver->resolveFromFfbbCode('XX0059'));  // 59 Nord
        self::assertSame('C', $this->resolver->resolveFromFfbbCode('XX0075'));  // 75 Paris
        self::assertSame('B', $this->resolver->resolveFromFfbbCode('CORSE2A')); // Corse → B
    }

    public function testSpecExampleEmbeddedDepartment(): void
    {
        // Spec §4bis: …0069… → 69 = Rhône, zone A.
        self::assertSame('A', $this->resolver->resolveFromFfbbCode('LR0069IDF'));
    }

    public function testAmbiguousCodeDegradesToNull(): void
    {
        // Two plausible departments (69 and 12) → refuse to guess.
        self::assertNull($this->resolver->resolveFromFfbbCode('69ABC12'));
    }

    public function testUndecidableCodeReturnsNull(): void
    {
        self::assertNull($this->resolver->resolveFromFfbbCode('ABCDEF'));
        self::assertNull($this->resolver->resolveFromFfbbCode(null));
        self::assertNull($this->resolver->resolveFromFfbbCode(''));
    }

    protected function setUp(): void
    {
        $this->resolver = new SchoolZoneResolver;
    }
}
