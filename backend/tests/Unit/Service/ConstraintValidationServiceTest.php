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

        self::assertContains('Cette contrainte doit cibler une équipe, un coach ou un gymnase précis.', $errors);
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

        self::assertContains('Cette contrainte doit cibler une équipe, un coach ou un gymnase précis.', $errors);
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
        self::assertContains('L\'heure « à partir de » doit être au format HH:MM.', $errors);
        // A malformed bound must NOT also trigger the "before" comparison — that
        // would emit a second, misleading error for one bad field (Lot C review).
        self::assertNotContains('L\'heure de début doit précéder l\'heure de fin.', $errors);

        $constraint->setConfig(['coachId' => 'coach-1', 'fromTime' => '20:00', 'untilTime' => '18:00']);
        self::assertContains('L\'heure de début doit précéder l\'heure de fin.', $this->service->validate($constraint));
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

        self::assertContains('Cette contrainte doit cibler une équipe, un coach ou un gymnase précis.', $errors);
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

        self::assertContains('Une contrainte « toutes les équipes » ne doit pas cibler une équipe précise.', $errors);
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

        self::assertNotContains('Cette contrainte doit cibler une équipe, un coach ou un gymnase précis.', $errors);
        self::assertNotContains('Une contrainte « toutes les équipes » ne doit pas cibler une équipe précise.', $errors);
    }

    public function testTimeFamilyRequiresMaxOrMinStartTime(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig([]);

        $errors = $this->service->validate($constraint);

        self::assertContains('Une contrainte d\'horaire doit préciser au moins une heure (début au plus tôt, au plus tard, ou fin).', $errors);
    }

    public function testDayFamilyRequiresAllowedOrForbiddenDays(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::DAY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig([]);

        $errors = $this->service->validate($constraint);

        self::assertContains('Une contrainte de jour doit préciser au moins un jour (autorisé, à éviter ou imposé).', $errors);
    }

    public function testFacilityFamilyRequiresAVenueKey(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig([]);

        $errors = $this->service->validate($constraint);

        self::assertContains('Une contrainte de gymnase doit désigner un gymnase.', $errors);
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

        self::assertContains('Une contrainte de gymnase doit désigner un gymnase.', $this->service->validate($constraint));
    }

    public function testCoachAvailabilityFamilyRequiresCoachIdOrTargetTag(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig([]);

        $errors = $this->service->validate($constraint);

        self::assertContains('Une contrainte de disponibilité doit cibler un coach.', $errors);
    }

    public function testFacilityCapacityFamilyRequiresMaxTeams(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::FACILITY_CAPACITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig([]);

        $errors = $this->service->validate($constraint);

        self::assertContains('Une contrainte de capacité doit préciser un nombre maximum d\'équipes.', $errors);
    }

    public function testLockRuleTypeOnlyValidForTimeOrDay(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::LOCK);
        $constraint->setConfig(['venueId' => 'venue-1']);

        $errors = $this->service->validate($constraint);

        self::assertContains('Le verrouillage n\'est possible que sur une contrainte d\'horaire ou de jour.', $errors);
    }

    public function testLockRuleTypeValidForTimeFamily(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::LOCK);
        $constraint->setConfig(['maxStartTime' => '20:00']);

        $errors = $this->service->validate($constraint);

        self::assertNotContains('Le verrouillage n\'est possible que sur une contrainte d\'horaire ou de jour.', $errors);
    }

    public function testLockRuleTypeValidForDayFamily(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::DAY);
        $constraint->setRuleType(ConstraintRuleType::LOCK);
        $constraint->setConfig(['allowedDays' => [1, 2]]);

        $errors = $this->service->validate($constraint);

        self::assertNotContains('Le verrouillage n\'est possible que sur une contrainte d\'horaire ou de jour.', $errors);
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
        self::assertContains('« Fini avant » n\'existe qu\'en règle OBLIGATOIRE — passez la contrainte en obligatoire, sinon elle serait ignorée.', $this->service->validate($constraint));
    }

    public function testMinAtVenueIdAtPreferredIsRejected(): void
    {
        $constraint = (new Constraint)->setScope(ConstraintScope::TEAM)->setScopeTargetId('t')->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::PREFERRED)->setConfig(['minAtVenueId' => 'v']);
        self::assertContains('« Au moins N séances dans ce gymnase » n\'existe qu\'en règle OBLIGATOIRE — passez la contrainte en obligatoire.', $this->service->validate($constraint));
    }

    public function testMinAtVenueIdAtClubScopeIsRejected(): void
    {
        // CLUB-scoped minAtVenueId is dropped by parse_v2_constraints (TEAM-only) — reject it (C3).
        $constraint = (new Constraint)->setScope(ConstraintScope::CLUB)->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)->setConfig(['minAtVenueId' => 'v']);
        self::assertContains('« Au moins N séances dans ce gymnase » se pose sur une équipe ou un groupe — pas sur « toutes les équipes ».', $this->service->validate($constraint));
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
