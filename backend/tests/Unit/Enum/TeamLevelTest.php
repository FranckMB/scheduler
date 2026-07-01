<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\TeamLevel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ValueError;

#[Group('unit')]
final class TeamLevelTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        self::assertSame('ELITE', TeamLevel::ELITE->value);
        self::assertSame('REGIONAL', TeamLevel::REGIONAL->value);
        self::assertSame('NATIONAL', TeamLevel::NATIONAL->value);
        self::assertSame('DEPARTEMENTAL', TeamLevel::DEPARTEMENTAL->value);
        self::assertSame('LOISIR_ADULTE', TeamLevel::LOISIR_ADULTE->value);
        self::assertSame('LOISIR_JEUNE', TeamLevel::LOISIR_JEUNE->value);
        self::assertSame('HONNEUR', TeamLevel::HONNEUR->value);
        self::assertSame('PROMOTION', TeamLevel::PROMOTION->value);
        self::assertSame('PRE_REGION', TeamLevel::PRE_REGION->value);
    }

    public function testFromValidValues(): void
    {
        self::assertSame(TeamLevel::ELITE, TeamLevel::from('ELITE'));
        self::assertSame(TeamLevel::LOISIR_ADULTE, TeamLevel::from('LOISIR_ADULTE'));
        self::assertSame(TeamLevel::LOISIR_JEUNE, TeamLevel::from('LOISIR_JEUNE'));
        self::assertSame(TeamLevel::PRE_REGION, TeamLevel::from('PRE_REGION'));
    }

    public function testFromInvalidValueThrows(): void
    {
        $this->expectException(ValueError::class);
        TeamLevel::from('UNKNOWN');
    }
}
