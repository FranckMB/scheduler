<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\Team;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Entity\VenueTrainingSlot;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Service\ScheduleConstraintBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

final class ScheduleConstraintBuilderTest extends TestCase
{
    private ScheduleConstraintBuilder $builder;

    private EntityManagerInterface&MockObject $entityManager;

    /** @var EntityRepository<TeamTag>&MockObject */
    private EntityRepository&MockObject $teamTagRepository;

    /** @var EntityRepository<TeamTagAssignment>&MockObject */
    private EntityRepository&MockObject $teamTagAssignmentRepository;

    private LoggerInterface&MockObject $logger;

    public function testResolveTagFiltersByClubId(): void
    {
        $seasonId = 'season-1';
        $club1Id = 'club-1';
        $tag = (new TeamTag)
            ->setId('tag-jeune-club-1')
            ->setClubId($club1Id)
            ->setName('JEUNE');

        $this->teamTagRepository->expects(self::once())
            ->method('findOneBy')
            ->with(self::callback(static fn (array $criteria): bool => 'JEUNE' === ($criteria['name'] ?? null)
                    && $club1Id === ($criteria['clubId'] ?? null)))
            ->willReturn($tag);

        $this->teamTagAssignmentRepository->method('findBy')->willReturnCallback(
            static function (array $criteria): array {
                if ('tag-jeune-club-1' !== ($criteria['tagId'] ?? null)) {
                    return [];
                }

                $first = (new TeamTagAssignment)
                    ->setTeamId('team-a')
                    ->setTagId('tag-jeune-club-1')
                    ->setSeasonId('season-1');
                $second = (new TeamTagAssignment)
                    ->setTeamId('team-b')
                    ->setTagId('tag-jeune-club-1')
                    ->setSeasonId('season-1');

                return [$first, $second];
            },
        );

        $club1TeamIds = $this->invokeResolveTagToTeamIds('JEUNE', $seasonId, $club1Id);
        self::assertSame(['team-a', 'team-b'], $club1TeamIds);
    }

    public function testResolveTagLogsWarningWhenNotFound(): void
    {
        $seasonId = 'season-1';
        $clubId = 'club-1';

        $this->teamTagRepository->method('findOneBy')->willReturn(null);
        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                self::callback(static fn (string $message): bool => str_contains($message, 'JEUNE') && str_contains($message, $clubId)),
                self::callback(static fn (array $context): bool => 'JEUNE' === ($context['targetTag'] ?? null)
                        && $clubId === ($context['clubId'] ?? null)
                        && $seasonId === ($context['seasonId'] ?? null)),
            );

        $result = $this->invokeResolveTagToTeamIds('JEUNE', $seasonId, $clubId);

        self::assertSame([], $result);
    }

    public function testIndivisibleVenueForcesSlotCapacityToOne(): void
    {
        $slot = (new VenueTrainingSlot)
            ->setDayOfWeek(1)
            ->setStartTime(new DateTimeImmutable('18:00'))
            ->setDurationMinutes(90)
            ->setCapacity(2);

        $method = new ReflectionMethod($this->builder, 'buildTrainingSlots');
        $method->setAccessible(true);

        /** @var array<int, array{capacity: int}> $indivisible */
        $indivisible = $method->invoke($this->builder, [$slot], false);
        self::assertSame(1, $indivisible[0]['capacity'], 'indivisible venue caps at 1');

        /** @var array<int, array{capacity: int}> $splittable */
        $splittable = $method->invoke($this->builder, [$slot], true);
        self::assertSame(2, $splittable[0]['capacity'], 'splittable venue keeps slot capacity');
    }

    public function testPriorityTierConstraintDoesNotSendOrToolsWeight(): void
    {
        $tier = (new PriorityTier)
            ->setId(1)
            ->setLabel('S')
            ->setOrToolsWeight(10000)
            ->setDefaultMinSessions(2);

        $method = new ReflectionMethod($this->builder, 'serializePriorityTierConstraints');
        $method->setAccessible(true);

        /** @var array<int, array{value: mixed, metadata: array<string, mixed>}> $result */
        $result = $method->invoke($this->builder, [$tier]);

        self::assertNull($result[0]['value']);
        self::assertArrayNotHasKey('orToolsWeight', $result[0]['metadata']);
        self::assertSame(2, $result[0]['metadata']['defaultMinSessions']);
    }

    public function testClubWideConstraintExpandsToEveryTeam(): void
    {
        // Audit P0.1 (dead "Toutes les équipes" cell): a CLUB-scope TIME/DAY/
        // FACILITY rule must reach the engine as one TEAM constraint per team —
        // the engine only applies these families to a team target.
        $constraint = (new Constraint)
            ->setId('c-club')
            ->setName('Toutes les équipes · pas mercredi')
            ->setScope(ConstraintScope::CLUB)
            ->setFamily(ConstraintFamily::DAY)
            ->setRuleType(ConstraintRuleType::PREFERRED)
            ->setConfig(['forbiddenDays' => [3]])
            ->setSortOrder(0)
            ->setIsActive(true);
        $teams = [$this->team('team-a'), $this->team('team-b')];

        $serialized = $this->invokeSerializeUnified([$constraint], 'season-1', 'club-1', $teams);

        self::assertCount(2, $serialized);
        self::assertSame(['team-a', 'team-b'], array_column($serialized, 'scopeTargetId'));
        foreach ($serialized as $row) {
            self::assertSame('TEAM', $row['scope']);
            self::assertSame('DAY', $row['family']);
            self::assertSame('PREFERRED', $row['ruleType']);
            self::assertSame(['forbiddenDays' => [3]], $row['config']);
        }
    }

    public function testEmptyTagResolutionIsANoOpNeverAClubWideBan(): void
    {
        // Review NR: a HARD "prefer venue" on a tag resolving to ZERO teams used
        // to skip the positive constraints but still run the "forbidden outside
        // the tag" loop — banning the venue for EVERY team of the club.
        $this->teamTagRepository->method('findOneBy')->willReturn(null);

        $constraint = (new Constraint)
            ->setId('c-tag')
            ->setName('Groupe fantôme · préfère Gymnase A')
            ->setScope(ConstraintScope::CLUB)
            ->setFamily(ConstraintFamily::FACILITY)
            ->setRuleType(ConstraintRuleType::HARD)
            ->setConfig(['targetTag' => 'FANTOME', 'preferredVenueId' => 'v-1'])
            ->setSortOrder(0)
            ->setIsActive(true);

        $serialized = $this->invokeSerializeUnified([$constraint], 'season-1', 'club-1', [$this->team('team-a'), $this->team('team-b')]);

        self::assertSame([], $serialized, 'an unresolvable tag must emit NOTHING (no club-wide forbiddenVenueId)');
    }

    public function testForcedVenueOnTagReservesItExclusively(): void
    {
        // Review NR (PR #120, F2): "impose" (forcedVenueId) on a group must DEDICATE
        // the venue — forbid it for teams outside the tag — exactly like HARD
        // "préfère" (preferredVenueId), else the strong-sounding impose would be
        // weaker than a mere preference (the tagged group forced in, others free to
        // pile in too).
        $tag = (new TeamTag)->setId('tag-fem')->setClubId('club-1')->setName('FEMININE');
        $this->teamTagRepository->method('findOneBy')->willReturn($tag);
        $this->teamTagAssignmentRepository->method('findBy')->willReturn([
            (new TeamTagAssignment)->setTeamId('team-a')->setTagId('tag-fem')->setSeasonId('season-1'),
        ]);

        $constraint = (new Constraint)
            ->setId('c-impose')
            ->setName('Groupe FEMININE · impose Gymnase A')
            ->setScope(ConstraintScope::CLUB)
            ->setFamily(ConstraintFamily::FACILITY)
            ->setRuleType(ConstraintRuleType::HARD)
            ->setConfig(['targetTag' => 'FEMININE', 'forcedVenueId' => 'v-1'])
            ->setSortOrder(0)
            ->setIsActive(true);

        $serialized = $this->invokeSerializeUnified([$constraint], 'season-1', 'club-1', [$this->team('team-a'), $this->team('team-b')]);

        $forced = array_values(array_filter($serialized, static fn (array $r): bool => 'v-1' === ($r['config']['forcedVenueId'] ?? null)));
        $forbidden = array_values(array_filter($serialized, static fn (array $r): bool => 'v-1' === ($r['config']['forbiddenVenueId'] ?? null)));
        self::assertCount(1, $forced, 'the tagged team is forced onto the venue');
        self::assertSame('team-a', $forced[0]['scopeTargetId']);
        self::assertCount(1, $forbidden, 'a non-tag team gets the venue forbidden (exclusivity)');
        self::assertSame('team-b', $forbidden[0]['scopeTargetId']);
    }

    public function testCoachAvailabilityIsNeverClubExpanded(): void
    {
        $constraint = (new Constraint)
            ->setId('c-coach')
            ->setName('coach indispo')
            ->setScope(ConstraintScope::COACH)
            ->setFamily(ConstraintFamily::COACH_AVAILABILITY)
            ->setRuleType(ConstraintRuleType::HARD)
            ->setConfig(['coachId' => 'co-1', 'unavailableDays' => [1]])
            ->setSortOrder(0)
            ->setIsActive(true);

        $serialized = $this->invokeSerializeUnified([$constraint], 'season-1', 'club-1', [$this->team('team-a')]);

        self::assertCount(1, $serialized);
        self::assertSame('COACH', $serialized[0]['scope']);
    }

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->teamTagRepository = $this->createMock(EntityRepository::class);
        $this->teamTagAssignmentRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->entityManager->method('getRepository')->willReturnMap([
            [TeamTag::class, $this->teamTagRepository],
            [TeamTagAssignment::class, $this->teamTagAssignmentRepository],
        ]);

        $this->builder = new ScheduleConstraintBuilder($this->logger, $this->entityManager);
    }

    private function team(string $id): Team
    {
        $team = new Team;
        $team->setId($id);
        $team->setName($id);

        return $team;
    }

    /**
     * @param list<Constraint> $constraints
     * @param list<Team>       $teams
     *
     * @return array<array<string, mixed>>
     */
    private function invokeSerializeUnified(array $constraints, string $seasonId, string $clubId, array $teams): array
    {
        $method = new ReflectionMethod($this->builder, 'serializeUnifiedConstraints');
        $method->setAccessible(true);

        /** @var array<array<string, mixed>> $result */
        $result = $method->invoke($this->builder, $constraints, $seasonId, $clubId, $teams);

        return $result;
    }

    /** @return list<string> */
    private function invokeResolveTagToTeamIds(string $targetTag, string $seasonId, string $clubId): array
    {
        $method = new ReflectionMethod($this->builder, 'resolveTagToTeamIds');
        $method->setAccessible(true);

        /** @var list<string> $result */
        $result = $method->invoke($this->builder, $targetTag, $seasonId, $clubId);

        return $result;
    }
}
