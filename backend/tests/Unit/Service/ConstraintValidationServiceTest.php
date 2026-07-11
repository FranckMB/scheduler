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
final class ConstraintValidationServiceTest extends TestCase
{
    private ConstraintValidationService $service;

    public function testTeamScopeRequiresScopeTargetId(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::TEAM);
        $constraint->setScopeTargetId(null);
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['maxStartTime' => '20:00']);

        $errors = $this->service->validate($constraint);

        self::assertContains('Scope TEAM requires a scope_target_id.', $errors);
    }

    public function testCoachScopeRequiresScopeTargetId(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::COACH);
        $constraint->setScopeTargetId(null);
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['coachId' => 'coach-1']);

        $errors = $this->service->validate($constraint);

        self::assertContains('Scope COACH requires a scope_target_id.', $errors);
    }

    public function testCoachAvailabilityAcceptsAValidTimeWindow(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::COACH);
        $constraint->setScopeTargetId('coach-1');
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['coachId' => 'coach-1', 'unavailableDays' => [2], 'fromTime' => '20:00']);

        self::assertSame([], $this->service->validate($constraint));
    }

    public function testCoachAvailabilityRejectsAMalformedOrInvertedWindow(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::COACH);
        $constraint->setScopeTargetId('coach-1');
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['coachId' => 'coach-1', 'unavailableDays' => [2], 'fromTime' => '25:99', 'untilTime' => '20:00']);

        $errors = $this->service->validate($constraint);
        self::assertContains('fromTime must be a HH:MM time.', $errors);
        // A malformed bound must NOT also trigger the "before" comparison — that
        // would emit a second, misleading error for one bad field (Lot C review).
        self::assertNotContains('fromTime must be before untilTime.', $errors);

        $constraint->setConfig(['coachId' => 'coach-1', 'fromTime' => '20:00', 'untilTime' => '18:00']);
        self::assertContains('fromTime must be before untilTime.', $this->service->validate($constraint));
    }

    public function testFacilityScopeRequiresScopeTargetId(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::FACILITY);
        $constraint->setScopeTargetId(null);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['venueId' => 'venue-1']);

        $errors = $this->service->validate($constraint);

        self::assertContains('Scope FACILITY requires a scope_target_id.', $errors);
    }

    public function testClubScopeShouldNotHaveScopeTargetId(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setScopeTargetId('team-1');
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['maxStartTime' => '20:00']);

        $errors = $this->service->validate($constraint);

        self::assertContains('Scope CLUB should not have a scope_target_id.', $errors);
    }

    public function testValidTeamScopeWithTargetIdHasNoScopeError(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::TEAM);
        $constraint->setScopeTargetId('team-1');
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['maxStartTime' => '20:00']);

        $errors = $this->service->validate($constraint);

        self::assertNotContains('Scope TEAM requires a scope_target_id.', $errors);
        self::assertNotContains('Scope CLUB should not have a scope_target_id.', $errors);
    }

    public function testTimeFamilyRequiresMaxOrMinStartTime(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig([]);

        $errors = $this->service->validate($constraint);

        self::assertContains('TIME family requires maxStartTime, minStartTime or maxEndTime in config.', $errors);
    }

    public function testDayFamilyRequiresAllowedOrForbiddenDays(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::DAY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig([]);

        $errors = $this->service->validate($constraint);

        self::assertContains('DAY family requires allowedDays, forbiddenDays or forcedDays in config.', $errors);
    }

    public function testFacilityFamilyRequiresAVenueKey(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig([]);

        $errors = $this->service->validate($constraint);

        self::assertContains('FACILITY family requires forcedVenueId, forbiddenVenueId, preferredVenueId or minAtVenueId in config.', $errors);
    }

    public function testFacilityFamilyAcceptsTheThreeEngineHonoredKeys(): void
    {
        foreach (['forcedVenueId', 'forbiddenVenueId', 'preferredVenueId'] as $key) {
            $constraint = new Constraint;
            $constraint->setScope(ConstraintScope::CLUB);
            $constraint->setFamily(ConstraintFamily::FACILITY);
            $constraint->setRuleType(ConstraintRuleType::HARD);
            $constraint->setConfig([$key => 42]);

            self::assertSame([], $this->service->validate($constraint), \sprintf('%s should be a valid FACILITY key', $key));
        }
    }

    public function testFacilityFamilyRejectsBareVenueIdWhichTheEngineIgnores(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::TEAM);
        $constraint->setScopeTargetId('team-1');
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['venueId' => 42]);

        self::assertContains('FACILITY family requires forcedVenueId, forbiddenVenueId, preferredVenueId or minAtVenueId in config.', $this->service->validate($constraint));
    }

    public function testCoachAvailabilityFamilyRequiresCoachIdOrTargetTag(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig([]);

        $errors = $this->service->validate($constraint);

        self::assertContains('COACH_AVAILABILITY family requires coachId or targetTag in config.', $errors);
    }

    public function testFacilityCapacityFamilyRequiresMaxTeams(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::FACILITY_CAPACITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig([]);

        $errors = $this->service->validate($constraint);

        self::assertContains('FACILITY_CAPACITY family requires maxTeams in config.', $errors);
    }

    public function testLockRuleTypeOnlyValidForTimeOrDay(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::LOCK);
        $constraint->setConfig(['venueId' => 'venue-1']);

        $errors = $this->service->validate($constraint);

        self::assertContains('LOCK rule type is only valid for TIME or DAY family.', $errors);
    }

    public function testLockRuleTypeValidForTimeFamily(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::LOCK);
        $constraint->setConfig(['maxStartTime' => '20:00']);

        $errors = $this->service->validate($constraint);

        self::assertNotContains('LOCK rule type is only valid for TIME or DAY family.', $errors);
    }

    public function testLockRuleTypeValidForDayFamily(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::DAY);
        $constraint->setRuleType(ConstraintRuleType::LOCK);
        $constraint->setConfig(['allowedDays' => [1, 2]]);

        $errors = $this->service->validate($constraint);

        self::assertNotContains('LOCK rule type is only valid for TIME or DAY family.', $errors);
    }

    public function testTimeFamilyAcceptsMaxEndTime(): void
    {
        $constraint = (new Constraint)->setScope(ConstraintScope::CLUB)->setFamily(ConstraintFamily::TIME)->setRuleType(ConstraintRuleType::HARD)->setConfig(['maxEndTime' => '20:30']);
        self::assertSame([], $this->service->validate($constraint));
    }

    public function testFacilityFamilyAcceptsMinAtVenueId(): void
    {
        $constraint = (new Constraint)->setScope(ConstraintScope::TEAM)->setScopeTargetId('t')->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)->setConfig(['minAtVenueId' => 'v', 'minAtVenueCount' => 1]);
        self::assertSame([], $this->service->validate($constraint));
    }

    public function testMaxEndTimeAtPreferredIsRejected(): void
    {
        // The engine only honors maxEndTime on HARD/LOCK — a PREFERRED end-bound is a placebo (C4).
        $constraint = (new Constraint)->setScope(ConstraintScope::CLUB)->setFamily(ConstraintFamily::TIME)->setRuleType(ConstraintRuleType::PREFERRED)->setConfig(['maxEndTime' => '20:30']);
        self::assertContains('maxEndTime requires an obligatory (HARD/LOCK) rule — the soft path ignores it.', $this->service->validate($constraint));
    }

    public function testMinAtVenueIdAtPreferredIsRejected(): void
    {
        $constraint = (new Constraint)->setScope(ConstraintScope::TEAM)->setScopeTargetId('t')->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::PREFERRED)->setConfig(['minAtVenueId' => 'v']);
        self::assertContains('minAtVenueId requires an obligatory (HARD/LOCK) rule — the soft path ignores it.', $this->service->validate($constraint));
    }

    public function testMinAtVenueIdAtClubScopeIsRejected(): void
    {
        // CLUB-scoped minAtVenueId is dropped by parse_v2_constraints (TEAM-only) — reject it (C3).
        $constraint = (new Constraint)->setScope(ConstraintScope::CLUB)->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)->setConfig(['minAtVenueId' => 'v']);
        self::assertContains('minAtVenueId requires a team target (scope TEAM) — "au moins N ici" is per-team.', $this->service->validate($constraint));
    }

    public function testVenueMinimumErrorWhenCountExceedsSessions(): void
    {
        $constraint = (new Constraint)->setFamily(ConstraintFamily::FACILITY)->setConfig(['minAtVenueId' => 'v', 'minAtVenueCount' => 3]);
        self::assertNotNull($this->service->venueMinimumError($constraint, 2), 'min 3 > 2 sessions/week must error');
    }

    public function testVenueMinimumErrorNullWhenWithinSessions(): void
    {
        $constraint = (new Constraint)->setFamily(ConstraintFamily::FACILITY)->setConfig(['minAtVenueId' => 'v', 'minAtVenueCount' => 1]);
        self::assertNull($this->service->venueMinimumError($constraint, 2));
        // Non venue-minimum → always null.
        $other = (new Constraint)->setFamily(ConstraintFamily::FACILITY)->setConfig(['forcedVenueId' => 'v']);
        self::assertNull($this->service->venueMinimumError($other, 1));
    }

    protected function setUp(): void
    {
        $this->service = new ConstraintValidationService;
    }
}
