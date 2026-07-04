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

    public function testZoneForDepartment(): void
    {
        self::assertSame('A', $this->resolver->zoneForDepartment('69')); // Rhône
        self::assertSame('B', $this->resolver->zoneForDepartment('59')); // Nord
        self::assertSame('C', $this->resolver->zoneForDepartment('75')); // Paris
        self::assertSame('B', $this->resolver->zoneForDepartment('2A')); // Corse-du-Sud
        self::assertSame('B', $this->resolver->zoneForDepartment('2b')); // case-insensitive
        self::assertNull($this->resolver->zoneForDepartment('99'));      // unknown/overseas
    }

    public function testResolveFromFfbbCode(): void
    {
        // Spec example: the department is embedded as digits (…0069… → 69 = Rhône, zone A).
        self::assertSame('A', $this->resolver->resolveFromFfbbCode('LR0069IDF'));
        // Prefix form.
        self::assertSame('A', $this->resolver->resolveFromFfbbCode('69ABC12'));
        // Corse token.
        self::assertSame('B', $this->resolver->resolveFromFfbbCode('COR2A001'));
        // Undecidable → null (falls back to manual schoolZone).
        self::assertNull($this->resolver->resolveFromFfbbCode('ABCDEF'));
        self::assertNull($this->resolver->resolveFromFfbbCode(null));
        self::assertNull($this->resolver->resolveFromFfbbCode(''));
    }

    protected function setUp(): void
    {
        $this->resolver = new SchoolZoneResolver;
    }
}
