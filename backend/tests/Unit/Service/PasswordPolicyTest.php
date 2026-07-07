<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('phase1')]
final class PasswordPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function passwords(): iterable
    {
        yield 'compliant (12 + upper + special)' => ['Password123!', true];
        yield 'compliant with accents' => ['Château-Fort-42', true];
        yield 'too short (11)' => ['Passw0rd!23', false];
        yield 'no uppercase' => ['password123!x', false];
        yield 'no special char' => ['Password12345', false];
        yield 'exactly 12 boundary ok' => ['Abcdefghij1!', true];
        yield 'empty' => ['', false];
    }

    #[DataProvider('passwords')]
    public function testPolicy(string $password, bool $expectedValid): void
    {
        $policy = new PasswordPolicy;

        self::assertSame($expectedValid, $policy->isValid($password));
        self::assertSame($expectedValid, null === $policy->validate($password));
    }

    public function testInvalidReturnsTheFrenchRequirement(): void
    {
        self::assertSame(PasswordPolicy::REQUIREMENT_FR, (new PasswordPolicy)->validate('weak'));
    }
}
