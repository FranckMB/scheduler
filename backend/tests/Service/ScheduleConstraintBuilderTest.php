<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\CoachUnavailability;
use App\Entity\PriorityTier;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamConstraint;
use App\Entity\Venue;
use App\Entity\VenueAvailability;
use App\Enum\LockLevel;
use App\Service\ScheduleConstraintBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class ScheduleConstraintBuilderTest extends TestCase
{
    private const CLUB_ID = '11111111-1111-1111-1111-111111111111';
    private const SEASON_ID = '22222222-2222-2222-2222-222222222222';

    public function testBuildPayloadUsesScheduleInputSchemaCamelCaseFields(): void
    {
        $payload = (new ScheduleConstraintBuilder())->buildPayload(
            clubId: self::CLUB_ID,
            seasonId: self::SEASON_ID,
            venues: [$this->venue('venue-1')],
            teams: [$this->team('team-1')],
            coaches: [$this->coach('coach-1')],
            solverSeed: 42,
        );

        self::assertSame('1.0', $payload['version']);
        self::assertSame(self::CLUB_ID, $payload['clubId']);
        self::assertSame(self::SEASON_ID, $payload['seasonId']);
        self::assertSame(42, $payload['solverSeed']);
        self::assertArrayNotHasKey('club_id', $payload);
        self::assertArrayNotHasKey('season_id', $payload);
        self::assertArrayHasKey('slotTemplates', $payload);
    }

    public function testVenueSerializationIncludesNullableSchemaFieldsGracefully(): void
    {
        $venue = $this->venue('venue-1')
            ->setIsExternal(true)
            ->setColor('#123456')
            ->setLatitude('48.8566000')
            ->setLongitude('2.3522000')
            ->setExternalRef('ext-1')
            ->setParentVenueId('parent-venue')
            ->setIsActive(false);

        $payload = (new ScheduleConstraintBuilder())->buildPayload(self::CLUB_ID, self::SEASON_ID, venues: [$venue]);

        self::assertSame([
            'id' => 'venue-1',
            'name' => 'Gym venue-1',
            'isExternal' => true,
            'color' => '#123456',
            'latitude' => '48.8566000',
            'longitude' => '2.3522000',
            'source' => 'manual',
            'externalRef' => 'ext-1',
            'isActive' => false,
            'parentVenueId' => 'parent-venue',
        ], $payload['venues'][0]);
    }

    public function testTeamAndCoachSerializationIncludesSchemaOptionalFields(): void
    {
        $team = $this->team('team-1')
            ->setGender('F')
            ->setMinSessionsOverride(1)
            ->setMatchDay(6)
            ->setForcedVenueId('venue-1')
            ->setParentTeamId('parent-team')
            ->setFfbbTeamId('ffbb-1');
        $coach = $this->coach('coach-1')
            ->setEmail('coach@example.test')
            ->setPhone('+33000000000')
            ->setMaxDaysOverride(3)
            ->setMaxDaysOverrideConfirmed(true)
            ->setAcceptableLateMinutes(15)
            ->setParentCoachId('parent-coach');

        $payload = (new ScheduleConstraintBuilder())->buildPayload(
            clubId: self::CLUB_ID,
            seasonId: self::SEASON_ID,
            teams: [$team],
            coaches: [$coach],
        );

        self::assertSame('sport-category-1', $payload['teams'][0]['sportCategoryId']);
        self::assertSame(1, $payload['teams'][0]['priorityTierId']);
        self::assertSame('venue-1', $payload['teams'][0]['forcedVenueId']);
        self::assertSame('coach@example.test', $payload['coaches'][0]['email']);
        self::assertTrue($payload['coaches'][0]['maxDaysOverrideConfirmed']);
        self::assertSame(15, $payload['coaches'][0]['acceptableLateMinutes']);
    }

    public function testCalculatesMaxDaysPerWeekFromUniqueVenueAvailabilityDays(): void
    {
        $builder = new ScheduleConstraintBuilder();

        $maxDays = $builder->calculateMaxDaysPerWeek([
            $this->venueAvailability('a1', 'venue-1', 1),
            $this->venueAvailability('a2', 'venue-1', 1),
            $this->venueAvailability('a3', 'venue-1', 3),
            $this->venueAvailability('a4', 'venue-2', 5),
        ]);

        self::assertSame(['venue-1' => 2, 'venue-2' => 1], $maxDays);
    }

    public function testMaxDaysPerWeekIsSerializedAsSchemaConstraintMetadata(): void
    {
        $payload = (new ScheduleConstraintBuilder())->buildPayload(
            self::CLUB_ID,
            self::SEASON_ID,
            venueAvailabilities: [
                $this->venueAvailability('a1', 'venue-1', 1),
                $this->venueAvailability('a2', 'venue-1', 2),
            ],
        );

        $constraint = $this->firstConstraintOfType($payload, 'MAX_DAYS_PER_WEEK');

        self::assertSame('max-days-per-week:venue-1', $constraint['id']);
        self::assertSame(2, $constraint['value']);
        self::assertSame(2, $constraint['metadata']['maxDaysPerWeek']);
    }

    public function testHardLockedSlotTemplatesAreExcludedFromSolverInput(): void
    {
        $payload = (new ScheduleConstraintBuilder())->buildPayload(
            self::CLUB_ID,
            self::SEASON_ID,
            slotTemplates: [
                $this->slotTemplate('slot-hard', LockLevel::HARD),
                $this->slotTemplate('slot-none', LockLevel::NONE),
            ],
        );

        self::assertSame(['slot-none'], array_column($payload['slotTemplates'], 'id'));
    }

    public function testSoftLockedSlotTemplatesRemainInSolverInputWithPenalty(): void
    {
        $payload = (new ScheduleConstraintBuilder())->buildPayload(
            self::CLUB_ID,
            self::SEASON_ID,
            slotTemplates: [$this->slotTemplate('slot-soft', LockLevel::SOFT)],
        );

        self::assertSame('SOFT', $payload['slotTemplates'][0]['lockLevel']);
        self::assertSame(10_000, $payload['slotTemplates'][0]['pendingConstraintSuggestion']['penalty']);
    }

    public function testNoneLockedSlotTemplatesRemainFreeOfPenalty(): void
    {
        $payload = (new ScheduleConstraintBuilder())->buildPayload(
            self::CLUB_ID,
            self::SEASON_ID,
            slotTemplates: [$this->slotTemplate('slot-none', LockLevel::NONE)],
        );

        self::assertSame('NONE', $payload['slotTemplates'][0]['lockLevel']);
        self::assertNull($payload['slotTemplates'][0]['pendingConstraintSuggestion']);
    }

    public function testCoachPlayerMembershipsAreIncludedAsCoachUnavailabilityConstraints(): void
    {
        $payload = (new ScheduleConstraintBuilder())->buildPayload(
            self::CLUB_ID,
            self::SEASON_ID,
            coachPlayerMemberships: [$this->coachPlayerMembership('membership-1', 'coach-1', 'team-1')],
        );

        $constraint = $this->firstConstraintOfType($payload, 'COACH_PLAYER_UNAVAILABILITY');

        self::assertSame('team-1', $constraint['teamId']);
        self::assertSame('coach-1', $constraint['metadata']['coachId']);
        self::assertSame('player', $constraint['metadata']['position']);
    }

    public function testTeamCoachPriorityTierAndUnavailabilityEntitiesBecomeConstraints(): void
    {
        $payload = (new ScheduleConstraintBuilder())->buildPayload(
            self::CLUB_ID,
            self::SEASON_ID,
            coachUnavailabilities: [$this->coachUnavailability('cu-1', 'coach-1')],
            teamCoaches: [$this->teamCoach('tc-1', 'team-1', 'coach-1')],
            priorityTiers: [$this->priorityTier(1)],
        );

        self::assertSame('coach-1', $this->firstConstraintOfType($payload, 'COACH_UNAVAILABILITY')['metadata']['coachId']);
        self::assertSame('HEAD', $this->firstConstraintOfType($payload, 'TEAM_COACH')['metadata']['role']);
        self::assertSame('A', $this->firstConstraintOfType($payload, 'PRIORITY_TIER')['metadata']['label']);
    }

    public function testTeamConstraintKeepsNullableFieldsInsideMetadataOnlyWhenPresent(): void
    {
        $constraint = (new TeamConstraint())
            ->setId('constraint-1')
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setTeamId('team-1')
            ->setType('FORBIDDEN')
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('18:00:00'))
            ->setEndTime(new \DateTimeImmutable('19:00:00'))
            ->setReason('No gym');

        $payload = (new ScheduleConstraintBuilder())->buildPayload(self::CLUB_ID, self::SEASON_ID, teamConstraints: [$constraint]);

        self::assertSame('FORBIDDEN', $payload['constraints'][0]['type']);
        self::assertSame('18:00:00', $payload['constraints'][0]['metadata']['startTime']);
        self::assertArrayNotHasKey('venueId', $payload['constraints'][0]['metadata']);
    }

    public function testCacheHitReturnsCachedPayloadAndSkipsDoctrineQueries(): void
    {
        $cachedPayload = ['clubId' => self::CLUB_ID, 'cached' => true];
        $cachePool = new InMemoryCachePool([ScheduleConstraintBuilder::cacheKey(self::CLUB_ID) => $cachedPayload]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');

        $payload = (new ScheduleConstraintBuilder($entityManager, $cachePool))->buildForClubSeason(self::CLUB_ID, self::SEASON_ID);

        self::assertSame($cachedPayload, $payload);
        self::assertSame(0, $cachePool->saveCount);
    }

    public function testCacheMissQueriesAllClubSeasonEntitiesAndSavesForFourHours(): void
    {
        $findByArguments = [];
        $repositories = [
            Venue::class => $this->repositoryReturning(Venue::class, [$this->venue('venue-1')], $findByArguments),
            Team::class => $this->repositoryReturning(Team::class, [$this->team('team-1')], $findByArguments),
            Coach::class => $this->repositoryReturning(Coach::class, [$this->coach('coach-1')], $findByArguments),
            TeamConstraint::class => $this->repositoryReturning(TeamConstraint::class, [], $findByArguments),
            VenueAvailability::class => $this->repositoryReturning(VenueAvailability::class, [$this->venueAvailability('a1', 'venue-1', 1)], $findByArguments),
            CoachUnavailability::class => $this->repositoryReturning(CoachUnavailability::class, [$this->coachUnavailability('cu-1', 'coach-1')], $findByArguments),
            TeamCoach::class => $this->repositoryReturning(TeamCoach::class, [$this->teamCoach('tc-1', 'team-1', 'coach-1')], $findByArguments),
            CoachPlayerMembership::class => $this->repositoryReturning(CoachPlayerMembership::class, [$this->coachPlayerMembership('membership-1', 'coach-1', 'team-1')], $findByArguments),
            ScheduleSlotTemplate::class => $this->repositoryReturning(ScheduleSlotTemplate::class, [$this->slotTemplate('slot-none', LockLevel::NONE)], $findByArguments),
            PriorityTier::class => $this->repositoryReturning(PriorityTier::class, [$this->priorityTier(1)], $findByArguments),
        ];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $className): EntityRepository => $repositories[$className],
        );
        $cachePool = new InMemoryCachePool();

        $payload = (new ScheduleConstraintBuilder($entityManager, $cachePool))->buildForClubSeason(self::CLUB_ID, self::SEASON_ID, 7);

        self::assertSame(7, $payload['solverSeed']);
        self::assertSame(1, $cachePool->saveCount);
        self::assertSame(14_400, $cachePool->lastSavedItem?->expiresAfter);
        self::assertSame(ScheduleConstraintBuilder::cacheKey(self::CLUB_ID), $cachePool->lastSavedItem?->getKey());
        foreach (array_keys($repositories) as $className) {
            if (PriorityTier::class === $className) {
                self::assertSame([[], ['id' => 'ASC']], $findByArguments[$className]);
                continue;
            }

            self::assertSame([['clubId' => self::CLUB_ID, 'seasonId' => self::SEASON_ID], ['id' => 'ASC']], $findByArguments[$className]);
        }
    }

    public function testCacheKeyMatchesInvalidationListenerScheduleInputKey(): void
    {
        self::assertSame('club:'.self::CLUB_ID.':schedule_input', ScheduleConstraintBuilder::cacheKey(self::CLUB_ID));
    }

    public function testPayloadHasStableSnapshotHashForSameInputs(): void
    {
        $builder = new ScheduleConstraintBuilder();
        $first = $builder->buildPayload(self::CLUB_ID, self::SEASON_ID, venues: [$this->venue('venue-1')]);
        $second = $builder->buildPayload(self::CLUB_ID, self::SEASON_ID, venues: [$this->venue('venue-1')]);

        self::assertSame(
            hash('sha256', json_encode($first, JSON_THROW_ON_ERROR)),
            hash('sha256', json_encode($second, JSON_THROW_ON_ERROR)),
        );
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function firstConstraintOfType(array $payload, string $type): array
    {
        foreach ($payload['constraints'] as $constraint) {
            if ($constraint['type'] === $type) {
                return $constraint;
            }
        }

        self::fail(sprintf('Constraint of type %s was not found.', $type));
    }

    /** @param class-string $className @param list<object> $items @param array<class-string, array{array<string, mixed>, array<string, string>}> $findByArguments */
    private function repositoryReturning(string $className, array $items, array &$findByArguments): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(
            static function (array $criteria, ?array $orderBy = null) use ($className, $items, &$findByArguments): array {
                $findByArguments[$className] = [$criteria, $orderBy ?? []];

                return $items;
            },
        );

        return $repository;
    }

    private function venue(string $id): Venue
    {
        return (new Venue())
            ->setId($id)
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setName('Gym '.$id)
            ->setSource('manual')
            ->setIsActive(true);
    }

    private function team(string $id): Team
    {
        return (new Team())
            ->setId($id)
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setSportCategoryId('sport-category-1')
            ->setPriorityTierId(1)
            ->setName('Team '.$id)
            ->setSessionsPerWeek(2)
            ->setIsActive(true);
    }

    private function coach(string $id): Coach
    {
        return (new Coach())
            ->setId($id)
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setFirstName('Coach')
            ->setLastName($id)
            ->setIsActive(true);
    }

    private function venueAvailability(string $id, string $venueId, int $dayOfWeek): VenueAvailability
    {
        return (new VenueAvailability())
            ->setId($id)
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setVenueId($venueId)
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(new \DateTimeImmutable('18:00:00'))
            ->setEndTime(new \DateTimeImmutable('20:00:00'));
    }

    private function coachUnavailability(string $id, string $coachId): CoachUnavailability
    {
        return (new CoachUnavailability())
            ->setId($id)
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setCoachId($coachId)
            ->setDayOfWeek(3)
            ->setStartTime(null)
            ->setEndTime(null);
    }

    private function teamCoach(string $id, string $teamId, string $coachId): TeamCoach
    {
        return (new TeamCoach())
            ->setId($id)
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setTeamId($teamId)
            ->setCoachId($coachId)
            ->setRole('HEAD')
            ->setIsRequired(true);
    }

    private function coachPlayerMembership(string $id, string $coachId, string $teamId): CoachPlayerMembership
    {
        return (new CoachPlayerMembership())
            ->setId($id)
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setCoachId($coachId)
            ->setTeamId($teamId)
            ->setPosition('player')
            ->setIsActive(true);
    }

    private function slotTemplate(string $id, LockLevel $lockLevel): ScheduleSlotTemplate
    {
        return (new ScheduleSlotTemplate())
            ->setId($id)
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setScheduleId('schedule-1')
            ->setTeamId('team-1')
            ->setVenueId('venue-1')
            ->setCoachId('coach-1')
            ->setDayOfWeek(1)
            ->setStartTime(new \DateTimeImmutable('18:30:00'))
            ->setDurationMinutes(90)
            ->setLockLevel($lockLevel);
    }

    private function priorityTier(int $id): PriorityTier
    {
        return (new PriorityTier())
            ->setId($id)
            ->setLabel('A')
            ->setName('Priority A')
            ->setColor('#ff0000')
            ->setOrToolsWeight(100)
            ->setDefaultMinSessions(2);
    }
}

final class InMemoryCachePool implements CacheItemPoolInterface
{
    public int $saveCount = 0;
    public ?InMemoryCacheItem $lastSavedItem = null;

    /** @param array<string, mixed> $values */
    public function __construct(private array $values = [])
    {
    }

    public function getItem(string $key): CacheItemInterface
    {
        return new InMemoryCacheItem($key, array_key_exists($key, $this->values), $this->values[$key] ?? null);
    }

    public function getItems(array $keys = []): iterable
    {
        return [];
    }

    public function hasItem(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function clear(string $prefix = ''): bool
    {
        $this->values = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->values[$key]);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        ++$this->saveCount;
        $this->lastSavedItem = $item;
        $this->values[$item->getKey()] = $item->get();

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }
}

final class InMemoryCacheItem implements CacheItemInterface
{
    public ?int $expiresAfter = null;

    public function __construct(private readonly string $key, private readonly bool $hit, private mixed $value)
    {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        return $this;
    }

    public function expiresAfter(\DateInterval|int|null $time): static
    {
        $this->expiresAfter = is_int($time) ? $time : null;

        return $this;
    }
}
