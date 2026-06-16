<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\Gender;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * @group unit
 */
final class GenderTest extends TestCase
{
    public function testCasesExist(): void
    {
        self::assertSame('M', Gender::M->value);
        self::assertSame('F', Gender::F->value);
        self::assertSame('MIXTE', Gender::MIXTE->value);
    }

    public function testFromValidValues(): void
    {
        self::assertSame(Gender::M, Gender::from('M'));
        self::assertSame(Gender::F, Gender::from('F'));
        self::assertSame(Gender::MIXTE, Gender::from('MIXTE'));
    }

    public function testFromInvalidValueThrows(): void
    {
        $this->expectException(ValueError::class);
        Gender::from('X');
    }
}
