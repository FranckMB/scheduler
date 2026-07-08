<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor;

use App\Dto\ConstraintInput;
use App\Entity\Constraint;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\State\Processor\AbstractStateProcessor;
use App\State\Processor\ConstraintStateProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

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

    /**
     * Review NR (audit BCK-09): a PUT must NEVER migrate an existing entity to the
     * request's current season — that silently moves a row across seasons of the
     * same club. processPut only stamps a season when the entity has none.
     */
    public function testProcessPutDoesNotMigrateAnEntitysSeason(): void
    {
        $entity = (new Constraint)
            ->setClubId('club-1')
            ->setSeasonId('season-A')
            ->setName('rule')
            ->setScope(ConstraintScope::CLUB)
            ->setScopeTargetId(null)
            ->setFamily(ConstraintFamily::TIME)
            ->setRuleType(ConstraintRuleType::HARD)
            ->setConfig(['maxStartTime' => '18:00'])
            ->setIsActive(true)
            ->setSortOrder(0);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($entity);

        $processor = (new ReflectionClass(ConstraintStateProcessor::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(AbstractStateProcessor::class, 'entityManager'))->setValue($processor, $em);

        $input = new ConstraintInput;
        $input->name = 'renamed';

        $method = new ReflectionMethod($processor, 'processPut');
        $method->setAccessible(true);
        // clubId matches the entity ; the request season (season-B) is DIFFERENT.
        $method->invoke($processor, $input, ['id' => 'c-1'], 'club-1', 'season-B');

        self::assertSame('season-A', $entity->getSeasonId(), 'a PUT must not migrate the entity to the request season');
    }
}
