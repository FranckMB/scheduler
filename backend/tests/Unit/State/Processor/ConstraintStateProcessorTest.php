<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor;

use App\Dto\ConstraintInput;
use App\Entity\Constraint;
use App\Enum\ConstraintScope;
use App\State\Processor\ConstraintStateProcessor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[Group('unit')]
final class ConstraintStateProcessorTest extends TestCase
{
    private function invokeUpdate(Constraint $entity, ConstraintInput $input): void
    {
        // updateEntityFromInput touches only the entity + input (never the ctor
        // services), so bypass the constructor — its deps (SeasonResolver, guards)
        // are all final and unmockable.
        $processor = (new ReflectionClass(ConstraintStateProcessor::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($processor, 'updateEntityFromInput');
        $method->setAccessible(true);
        $method->invoke($processor, $entity, $input);
    }

    /**
     * Review NR (PR #120, F4): widening a TEAM constraint to CLUB via PUT sends
     * scopeTargetId=null, but a null input field means "leave unchanged" — so the
     * processor must explicitly clear the target, else a stale team id survives
     * with scope=CLUB (mis-read as a closed venue by ScheduleConstraintBuilder).
     */
    public function testWideningToClubClearsStaleScopeTargetId(): void
    {
        $entity = (new Constraint)->setScope(ConstraintScope::TEAM)->setScopeTargetId('team-x');

        $input = new ConstraintInput;
        $input->scope = 'CLUB';
        $input->scopeTargetId = null; // widening → no target

        $this->invokeUpdate($entity, $input);

        self::assertSame(ConstraintScope::CLUB, $entity->getScope());
        self::assertNull($entity->getScopeTargetId(), 'a CLUB-scoped constraint must not keep a team target');
    }

    /**
     * A TEAM→TEAM edit that supplies a new target still updates it (the invariant
     * only clears the target when the scope actually becomes CLUB).
     */
    public function testTeamScopeKeepsItsTarget(): void
    {
        $entity = (new Constraint)->setScope(ConstraintScope::TEAM)->setScopeTargetId('team-x');

        $input = new ConstraintInput;
        $input->scope = 'TEAM';
        $input->scopeTargetId = 'team-y';

        $this->invokeUpdate($entity, $input);

        self::assertSame(ConstraintScope::TEAM, $entity->getScope());
        self::assertSame('team-y', $entity->getScopeTargetId());
    }
}
