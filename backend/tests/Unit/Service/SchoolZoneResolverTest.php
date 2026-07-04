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

    public function testResolveRealFfbbCodes(): void
    {
        // Format: 3-letter league + 4-digit dept + sequence.
        self::assertSame('B', $this->resolver->resolveFromFfbbCode('GES0067060')); // Strasbourg, Bas-Rhin
        self::assertSame('A', $this->resolver->resolveFromFfbbCode('ARA0069001')); // Rhône
        self::assertSame('C', $this->resolver->resolveFromFfbbCode('IDF0075001')); // Paris
        self::assertSame('A', $this->resolver->resolveFromFfbbCode('XXX0001001')); // Ain (zero-padded)
        self::assertSame('B', $this->resolver->resolveFromFfbbCode('COR0020001')); // Corse numeric
    }

    public function testDomDepartmentHasNoZone(): void
    {
        // Guyane (973) is a DOM → no A/B/C zone → manual entry.
        self::assertNull($this->resolver->resolveFromFfbbCode('GUY0973021'));
    }

    public function testMalformedCodeReturnsNull(): void
    {
        self::assertNull($this->resolver->resolveFromFfbbCode('ABCDEF'));   // no dept digits
        self::assertNull($this->resolver->resolveFromFfbbCode('12ABC34'));  // not the FFBB shape
        self::assertNull($this->resolver->resolveFromFfbbCode(null));
        self::assertNull($this->resolver->resolveFromFfbbCode(''));
    }

    protected function setUp(): void
    {
        $this->resolver = new SchoolZoneResolver;
    }
}
