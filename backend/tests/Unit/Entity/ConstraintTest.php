<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Constraint;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
final class ConstraintTest extends TestCase
{
    public function testUuidGeneratedOnConstruct(): void
    {
        $constraint = new Constraint;
        self::assertNotEmpty($constraint->getId());
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $constraint->getId());
    }

    public function testIsActiveDefaultTrue(): void
    {
        $constraint = new Constraint;
        self::assertTrue($constraint->getIsActive());
        self::assertTrue($constraint->isIsActive());
    }

    public function testSettersAndGetters(): void
    {
        $constraint = new Constraint;

        $constraint->setClubId('club-1');
        self::assertSame('club-1', $constraint->getClubId());

        $constraint->setSeasonId('season-1');
        self::assertSame('season-1', $constraint->getSeasonId());

        $constraint->setName('No late games');
        self::assertSame('No late games', $constraint->getName());

        $constraint->setDescription('Description text');
        self::assertSame('Description text', $constraint->getDescription());

        $constraint->setScope(ConstraintScope::TEAM);
        self::assertSame(ConstraintScope::TEAM, $constraint->getScope());

        $constraint->setScopeTargetId('team-1');
        self::assertSame('team-1', $constraint->getScopeTargetId());

        $constraint->setFamily(ConstraintFamily::TIME);
        self::assertSame(ConstraintFamily::TIME, $constraint->getFamily());

        $constraint->setRuleType(ConstraintRuleType::HARD);
        self::assertSame(ConstraintRuleType::HARD, $constraint->getRuleType());

        $constraint->setConfig(['maxStartTime' => '20:00']);
        self::assertSame(['maxStartTime' => '20:00'], $constraint->getConfig());

        $constraint->setCreatedBy('user-1');
        self::assertSame('user-1', $constraint->getCreatedBy());

        $constraint->setSource('manual');
        self::assertSame('manual', $constraint->getSource());

        $constraint->setSourceOccurrenceId('occ-1');
        self::assertSame('occ-1', $constraint->getSourceOccurrenceId());

        $constraint->setIsActive(false);
        self::assertFalse($constraint->getIsActive());

        $constraint->setSortOrder(5);
        self::assertSame(5, $constraint->getSortOrder());
    }

    public function testFluentInterface(): void
    {
        $constraint = new Constraint;
        self::assertSame($constraint, $constraint->setName('Test'));
        self::assertSame($constraint, $constraint->setClubId('c1'));
    }

    public function testTouchUpdatedAt(): void
    {
        $constraint = new Constraint;
        $originalUpdatedAt = $constraint->getUpdatedAt();

        // Sleep to ensure time difference
        usleep(1000);
        $constraint->touchUpdatedAt();

        self::assertGreaterThan($originalUpdatedAt, $constraint->getUpdatedAt());
    }
}
