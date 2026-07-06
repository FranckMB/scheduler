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

    public function testResolveMetropolitanFfbbCodes(): void
    {
        // Format: 3-letter league + 4-digit dept + sequence.
        self::assertSame('B', $this->resolver->resolveFromFfbbCode('GES0067060')); // Strasbourg, Bas-Rhin
        self::assertSame('A', $this->resolver->resolveFromFfbbCode('ARA0069001')); // Rhône
        self::assertSame('C', $this->resolver->resolveFromFfbbCode('IDF0075001')); // Paris
        self::assertSame('A', $this->resolver->resolveFromFfbbCode('XXX0001001')); // Ain (zero-padded)
    }

    public function testCorseHasItsOwnZone(): void
    {
        // Corse is no longer folded into zone B — it has its own regime.
        self::assertSame('CORSE', $this->resolver->resolveFromFfbbCode('COR0020001')); // Corse numeric
        self::assertSame('CORSE', $this->resolver->resolveFromFfbbCode('COR2A00001')); // Corse-du-Sud alpha
        self::assertSame('CORSE', $this->resolver->resolveFromFfbbCode('COR2B00001')); // Haute-Corse alpha
    }

    public function testOverseasTerritoriesResolveToTheirCode(): void
    {
        self::assertSame('GUADELOUPE', $this->resolver->resolveFromFfbbCode('GUA0971001'));
        self::assertSame('MARTINIQUE', $this->resolver->resolveFromFfbbCode('MAR0972001'));
        self::assertSame('GUYANE', $this->resolver->resolveFromFfbbCode('GUY0973021'));
        self::assertSame('REUNION', $this->resolver->resolveFromFfbbCode('REU0974001'));
        self::assertSame('SAINT_PIERRE_MIQUELON', $this->resolver->resolveFromFfbbCode('SPM0975001'));
        self::assertSame('MAYOTTE', $this->resolver->resolveFromFfbbCode('MAY0976001'));
        self::assertSame('WALLIS_FUTUNA', $this->resolver->resolveFromFfbbCode('WAF0986001'));
        self::assertSame('POLYNESIE', $this->resolver->resolveFromFfbbCode('PYF0987001'));
        self::assertSame('NOUVELLE_CALEDONIE', $this->resolver->resolveFromFfbbCode('NCL0988001'));
    }

    public function testUnknownTerritoryReturnsNull(): void
    {
        // Saint-Barthélemy (977) / Saint-Martin (978) are not in the calendar map.
        self::assertNull($this->resolver->resolveFromFfbbCode('SBH0977001'));
        self::assertNull($this->resolver->resolveFromFfbbCode('SXM0978001'));
    }

    public function testMalformedCodeReturnsNull(): void
    {
        self::assertNull($this->resolver->resolveFromFfbbCode('ABCDEF'));   // no dept digits
        self::assertNull($this->resolver->resolveFromFfbbCode('12ABC34'));  // not the FFBB shape
        self::assertNull($this->resolver->resolveFromFfbbCode('XXX0000001')); // dept 0 invalid
        self::assertNull($this->resolver->resolveFromFfbbCode(null));
        self::assertNull($this->resolver->resolveFromFfbbCode(''));
    }

    public function testEveryResolvedZoneIsInTheCanonicalCatalog(): void
    {
        $codes = ['GES0067060', 'ARA0069001', 'IDF0075001', 'COR0020001', 'GUY0973021', 'NCL0988001'];
        foreach ($codes as $code) {
            self::assertContains($this->resolver->resolveFromFfbbCode($code), SchoolZoneResolver::ZONES);
        }
    }

    protected function setUp(): void
    {
        $this->resolver = new SchoolZoneResolver;
    }
}
