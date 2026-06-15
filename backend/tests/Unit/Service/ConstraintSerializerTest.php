<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Constraint;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Service\ConstraintSerializer;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
final class ConstraintSerializerTest extends TestCase
{
    private ConstraintSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new ConstraintSerializer;
    }

    public function testSerializeTimeFamily(): void
    {
        $constraint = $this->createMock(Constraint::class);
        $constraint->method('getId')->willReturn('constraint-1');
        $constraint->method('getScope')->willReturn(ConstraintScope::TEAM);
        $constraint->method('getFamily')->willReturn(ConstraintFamily::TIME);
        $constraint->method('getRuleType')->willReturn(ConstraintRuleType::HARD);
        $constraint->method('getScopeTargetId')->willReturn('team-1');
        $constraint->method('getConfig')->willReturn([
            'minStartTime' => '18:00',
            'maxStartTime' => '21:00',
            'targetTag' => 'SENIOR',
        ]);

        $result = $this->serializer->serialize($constraint);

        self::assertSame('constraint-1', $result['id']);
        self::assertSame('TEAM', $result['scope']);
        self::assertSame('TIME', $result['family']);
        self::assertSame('HARD', $result['ruleType']);
        self::assertSame('team-1', $result['scopeTargetId']);
        self::assertSame('18:00', $result['minStartTime']);
        self::assertSame('21:00', $result['maxStartTime']);
        self::assertSame('SENIOR', $result['targetTag']);
    }

    public function testSerializeDayFamily(): void
    {
        $constraint = $this->createMock(Constraint::class);
        $constraint->method('getId')->willReturn('constraint-2');
        $constraint->method('getScope')->willReturn(ConstraintScope::CLUB);
        $constraint->method('getFamily')->willReturn(ConstraintFamily::DAY);
        $constraint->method('getRuleType')->willReturn(ConstraintRuleType::PREFERRED);
        $constraint->method('getScopeTargetId')->willReturn(null);
        $constraint->method('getConfig')->willReturn([
            'preferredDays' => [1, 3, 5],
            'forbiddenDays' => [2, 4],
        ]);

        $result = $this->serializer->serialize($constraint);

        self::assertSame('constraint-2', $result['id']);
        self::assertSame('CLUB', $result['scope']);
        self::assertSame('DAY', $result['family']);
        self::assertSame('PREFERRED', $result['ruleType']);
        self::assertArrayNotHasKey('scopeTargetId', $result);
        self::assertSame([1, 3, 5], $result['preferredDays']);
        self::assertSame([2, 4], $result['forbiddenDays']);
    }

    public function testSerializeFacilityFamily(): void
    {
        $constraint = $this->createMock(Constraint::class);
        $constraint->method('getId')->willReturn('constraint-3');
        $constraint->method('getScope')->willReturn(ConstraintScope::FACILITY);
        $constraint->method('getFamily')->willReturn(ConstraintFamily::FACILITY);
        $constraint->method('getRuleType')->willReturn(ConstraintRuleType::HARD);
        $constraint->method('getScopeTargetId')->willReturn('venue-1');
        $constraint->method('getConfig')->willReturn([
            'preferredVenueId' => 'venue-a',
            'forbiddenVenueId' => 'venue-b',
            'dateStart' => '2024-09-01',
            'dateEnd' => '2024-12-31',
        ]);

        $result = $this->serializer->serialize($constraint);

        self::assertSame('venue-1', $result['scopeTargetId']);
        self::assertSame('venue-a', $result['preferredVenueId']);
        self::assertSame('venue-b', $result['forbiddenVenueId']);
        self::assertSame('2024-09-01', $result['dateStart']);
        self::assertSame('2024-12-31', $result['dateEnd']);
    }

    public function testSerializeOmitsMissingConfigKeys(): void
    {
        $constraint = $this->createMock(Constraint::class);
        $constraint->method('getId')->willReturn('constraint-4');
        $constraint->method('getScope')->willReturn(ConstraintScope::TEAM);
        $constraint->method('getFamily')->willReturn(ConstraintFamily::TIME);
        $constraint->method('getRuleType')->willReturn(ConstraintRuleType::HARD);
        $constraint->method('getScopeTargetId')->willReturn(null);
        $constraint->method('getConfig')->willReturn([]);

        $result = $this->serializer->serialize($constraint);

        self::assertArrayNotHasKey('minStartTime', $result);
        self::assertArrayNotHasKey('maxStartTime', $result);
        self::assertArrayNotHasKey('targetTag', $result);
        self::assertArrayNotHasKey('scopeTargetId', $result);
    }
}
