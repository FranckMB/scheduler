<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Constraint;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Service\ConstraintValidationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ConflictDetectionServiceTest extends TestCase
{
    private ConstraintValidationService $service;

    public function testNoConflictsWithCompatibleConstraints(): void
    {
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::TEAM);
        $c1->setScopeTargetId('team-1');
        $c1->setFamily(ConstraintFamily::DAY);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['forbiddenDays' => [1, 2]]);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::TEAM);
        $c2->setScopeTargetId('team-1');
        $c2->setFamily(ConstraintFamily::DAY);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['forbiddenDays' => [3, 4]]);

        $conflicts = $this->service->detectConflicts([$c1, $c2]);

        self::assertCount(0, $conflicts);
    }

    public function testDetectsHardHardDayConflict(): void
    {
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::TEAM);
        $c1->setScopeTargetId('team-1');
        $c1->setFamily(ConstraintFamily::DAY);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['allowedDays' => [1, 2, 3]]);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::TEAM);
        $c2->setScopeTargetId('team-1');
        $c2->setFamily(ConstraintFamily::DAY);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['forbiddenDays' => [1]]);

        $conflicts = $this->service->detectConflicts([$c1, $c2]);

        self::assertCount(1, $conflicts);
        self::assertSame($c1, $conflicts[0]['constraint1']);
        self::assertSame($c2, $conflicts[0]['constraint2']);
        self::assertSame('Contradictory day constraints: allowed days overlap with forbidden days.', $conflicts[0]['reason']);
    }

    public function testDetectsHardHardTimeConflict(): void
    {
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::TEAM);
        $c1->setScopeTargetId('team-1');
        $c1->setFamily(ConstraintFamily::TIME);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['maxStartTime' => '18:00']);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::TEAM);
        $c2->setScopeTargetId('team-1');
        $c2->setFamily(ConstraintFamily::TIME);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['minStartTime' => '19:00']);

        $conflicts = $this->service->detectConflicts([$c1, $c2]);

        self::assertCount(1, $conflicts);
        self::assertSame('Contradictory time constraints: maxStartTime is less than minStartTime.', $conflicts[0]['reason']);
    }

    public function testNoConflictWithDifferentScopeTargetIds(): void
    {
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::TEAM);
        $c1->setScopeTargetId('team-1');
        $c1->setFamily(ConstraintFamily::DAY);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['allowedDays' => [1]]);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::TEAM);
        $c2->setScopeTargetId('team-2');
        $c2->setFamily(ConstraintFamily::DAY);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['forbiddenDays' => [1]]);

        $conflicts = $this->service->detectConflicts([$c1, $c2]);

        self::assertCount(0, $conflicts);
    }

    public function testNoConflictWithDifferentFamilies(): void
    {
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::TEAM);
        $c1->setScopeTargetId('team-1');
        $c1->setFamily(ConstraintFamily::TIME);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['maxStartTime' => '18:00']);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::TEAM);
        $c2->setScopeTargetId('team-1');
        $c2->setFamily(ConstraintFamily::DAY);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['forbiddenDays' => [1]]);

        $conflicts = $this->service->detectConflicts([$c1, $c2]);

        self::assertCount(0, $conflicts);
    }

    public function testNoConflictWithNonHardRuleType(): void
    {
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::TEAM);
        $c1->setScopeTargetId('team-1');
        $c1->setFamily(ConstraintFamily::DAY);
        $c1->setRuleType(ConstraintRuleType::PREFERRED);
        $c1->setConfig(['allowedDays' => [1]]);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::TEAM);
        $c2->setScopeTargetId('team-1');
        $c2->setFamily(ConstraintFamily::DAY);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['forbiddenDays' => [1]]);

        $conflicts = $this->service->detectConflicts([$c1, $c2]);

        self::assertCount(0, $conflicts);
    }

    public function testNoConflictBetweenDifferentTargetTags(): void
    {
        // EMB max 18:00 vs SENIOR min 18:50 are both CLUB-scoped but hit disjoint
        // teams → not a conflict.
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::CLUB);
        $c1->setFamily(ConstraintFamily::TIME);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['maxStartTime' => '18:00', 'targetTag' => 'EMB']);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::CLUB);
        $c2->setFamily(ConstraintFamily::TIME);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['minStartTime' => '18:50', 'targetTag' => 'SENIOR']);

        self::assertCount(0, $this->service->detectConflicts([$c1, $c2]));
    }

    public function testDetectsConflictBetweenUntaggedAndTaggedRule(): void
    {
        // An untagged CLUB rule (whole club) overlaps a tagged rule on that tag's
        // teams → a genuine contradiction must be reported.
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::CLUB);
        $c1->setFamily(ConstraintFamily::TIME);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['maxStartTime' => '18:00']); // no targetTag → all teams

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::CLUB);
        $c2->setFamily(ConstraintFamily::TIME);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['minStartTime' => '18:50', 'targetTag' => 'SENIOR']);

        self::assertCount(1, $this->service->detectConflicts([$c1, $c2]));
    }

    public function testDetectsTimeConflictRegardlessOfOrder(): void
    {
        // The min-rule is listed BEFORE the max-rule: an order-sensitive check would
        // miss it. Same-tag so the targets overlap.
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::TEAM);
        $c1->setScopeTargetId('team-1');
        $c1->setFamily(ConstraintFamily::TIME);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['minStartTime' => '19:00']);

        $c2 = new Constraint;
        $c2->setScope(ConstraintScope::TEAM);
        $c2->setScopeTargetId('team-1');
        $c2->setFamily(ConstraintFamily::TIME);
        $c2->setRuleType(ConstraintRuleType::HARD);
        $c2->setConfig(['maxStartTime' => '18:00']);

        self::assertCount(1, $this->service->detectConflicts([$c1, $c2]));
    }

    public function testEmptyConstraintsReturnsNoConflicts(): void
    {
        $conflicts = $this->service->detectConflicts([]);
        self::assertCount(0, $conflicts);
    }

    public function testSingleConstraintReturnsNoConflicts(): void
    {
        $c1 = new Constraint;
        $c1->setScope(ConstraintScope::TEAM);
        $c1->setScopeTargetId('team-1');
        $c1->setFamily(ConstraintFamily::DAY);
        $c1->setRuleType(ConstraintRuleType::HARD);
        $c1->setConfig(['forbiddenDays' => [1]]);

        $conflicts = $this->service->detectConflicts([$c1]);
        self::assertCount(0, $conflicts);
    }

    protected function setUp(): void
    {
        $this->service = new ConstraintValidationService;
    }
}
