<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\TeamLevel;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * @group unit
 */
final class TeamLevelTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        self::assertSame('ELITE', TeamLevel::ELITE->value);
        self::assertSame('REGIONAL', TeamLevel::REGIONAL->value);
        self::assertSame('NATIONAL', TeamLevel::NATIONAL->value);
        self::assertSame('DEPARTEMENTAL', TeamLevel::DEPARTEMENTAL->value);
        self::assertSame('LOISIR', TeamLevel::LOISIR->value);
        self::assertSame('HONNEUR', TeamLevel::HONNEUR->value);
        self::assertSame('PROMOTION', TeamLevel::PROMOTION->value);
        self::assertSame('PRE_REGION', TeamLevel::PRE_REGION->value);
    }

    public function testFromValidValues(): void
    {
        self::assertSame(TeamLevel::ELITE, TeamLevel::from('ELITE'));
        self::assertSame(TeamLevel::LOISIR, TeamLevel::from('LOISIR'));
        self::assertSame(TeamLevel::PRE_REGION, TeamLevel::from('PRE_REGION'));
    }

    public function testFromInvalidValueThrows(): void
    {
        $this->expectException(ValueError::class);
        TeamLevel::from('UNKNOWN');
    }
}
