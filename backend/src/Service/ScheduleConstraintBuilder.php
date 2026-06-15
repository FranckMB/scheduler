<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Entity\Venue;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ScheduleConstraintBuilder
{
    private const CACHE_TTL_SECONDS = 14_400;
    private const SCHEMA_VERSION = '2.0';
    private const DEFAULT_SOLVER_SEED = 42;
    private const SOFT_LOCK_PENALTY = 10_000;

    /** @var array<int, array{dayOfWeek: int, startTime: string, endTime: string}> */
    private const DEFAULT_VENUE_AVAILABILITY = [
        ['dayOfWeek' => 1, 'startTime' => '08:00', 'endTime' => '22:00'],
        ['dayOfWeek' => 2, 'startTime' => '08:00', 'endTime' => '22:00'],
        ['dayOfWeek' => 3, 'startTime' => '08:00', 'endTime' => '22:00'],
        ['dayOfWeek' => 4, 'startTime' => '08:00', 'endTime' => '22:00'],
        ['dayOfWeek' => 5, 'startTime' => '08:00', 'endTime' => '22:00'],
        ['dayOfWeek' => 6, 'startTime' => '08:00', 'endTime' => '22:00'],
    ];

    public function __construct(
        private readonly ?EntityManagerInterface $entityManager = null,
        #[Autowire(service: 'cache.schedule')]
        private readonly ?CacheItemPoolInterface $scheduleCachePool = null,
        private readonly ?TeamTagService $teamTagService = null,
    ) {}

    public static function cacheKey(string $clubId): string
    {
        return \sprintf('club.%s.schedule_input', $clubId);
    }

    private static function formatNullableTime(?DateTimeInterface $time): ?string
    {
        return $time?->format('H:i:s');
    }

    /** @return array<string, mixed> */
    public function buildForClubSeason(string $clubId, string $seasonId, int $solverSeed = self::DEFAULT_SOLVER_SEED, ?EntityManagerInterface $entityManager = null): array
    {
        $em = $entityManager ?? $this->entityManager;
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('ScheduleConstraintBuilder requires Doctrine for club/season builds.');
        }

        $cacheItem = null;
        if ($this->scheduleCachePool instanceof CacheItemPoolInterface) {
            $cacheItem = $this->scheduleCachePool->getItem(self::cacheKey($clubId));
            if ($cacheItem->isHit()) {
                $cached = $cacheItem->get();
                if (\is_array($cached)) {
                    return $cached;
                }
            }
        }

        $constraints = $em->getRepository(\App\Entity\Constraint::class)->findByClubSeason($clubId, $seasonId);

        $payload = $this->buildPayload(
            clubId: $clubId,
            seasonId: $seasonId,
            venues: $this->findByClubSeason(Venue::class, $clubId, $seasonId, $em),
            teams: $this->findByClubSeason(Team::class, $clubId, $seasonId, $em),
            coaches: $this->findByClubSeason(Coach::class, $clubId, $seasonId, $em),
            teamCoaches: $this->findByClubSeason(TeamCoach::class, $clubId, $seasonId, $em),
            coachPlayerMemberships: $this->findByClubSeason(CoachPlayerMembership::class, $clubId, $seasonId, $em),
            slotTemplates: $this->findByClubSeason(ScheduleSlotTemplate::class, $clubId, $seasonId, $em),
            priorityTiers: $em->getRepository(PriorityTier::class)->findBy([], ['id' => 'ASC']),
            solverSeed: $solverSeed,
            constraints: $constraints,
        );

        if ($cacheItem instanceof \Psr\Cache\CacheItemInterface) {
            $cacheItem->set($payload);
            $cacheItem->expiresAfter(self::CACHE_TTL_SECONDS);
            $this->scheduleCachePool->save($cacheItem);
        }

        return $payload;
    }

    /**
     * In-memory builder kept for existing cross-stack contract coverage.
     *
     * @param array<Venue>                  $venues
     * @param array<Team>                   $teams
     * @param array<Coach>                  $coaches
     * @param array<TeamCoach>              $teamCoaches
     * @param array<CoachPlayerMembership>  $coachPlayerMemberships
     * @param array<ScheduleSlotTemplate>   $slotTemplates
     * @param array<PriorityTier>           $priorityTiers
     * @param array<\App\Entity\Constraint> $constraints
     *
     * @return array<string, mixed>
     */
    public function build(
        array $venues,
        array $teams,
        array $coaches,
        array $teamCoaches = [],
        array $coachPlayerMemberships = [],
        array $slotTemplates = [],
        array $priorityTiers = [],
        array $constraints = [],
    ): array {
        return $this->buildPayload(
            clubId: $this->firstString($venues, 'getClubId')
                ?? $this->firstString($teams, 'getClubId')
                ?? $this->firstString($coaches, 'getClubId')
                ?? '',
            seasonId: $this->firstString($venues, 'getSeasonId')
                ?? $this->firstString($teams, 'getSeasonId')
                ?? $this->firstString($coaches, 'getSeasonId')
                ?? '',
            venues: $venues,
            teams: $teams,
            coaches: $coaches,
            teamCoaches: $teamCoaches,
            coachPlayerMemberships: $coachPlayerMemberships,
            slotTemplates: $slotTemplates,
            priorityTiers: $priorityTiers,
            constraints: $constraints,
        );
    }

    /**
     * @param array<Venue>                  $venues
     * @param array<Team>                   $teams
     * @param array<Coach>                  $coaches
     * @param array<TeamCoach>              $teamCoaches
     * @param array<CoachPlayerMembership>  $coachPlayerMemberships
     * @param array<ScheduleSlotTemplate>   $slotTemplates
     * @param array<PriorityTier>           $priorityTiers
     * @param array<\App\Entity\Constraint> $constraints
     *
     * @return array<string, mixed>
     */
    public function buildPayload(
        string $clubId,
        string $seasonId,
        array $venues = [],
        array $teams = [],
        array $coaches = [],
        array $teamCoaches = [],
        array $coachPlayerMemberships = [],
        array $slotTemplates = [],
        array $priorityTiers = [],
        int $solverSeed = self::DEFAULT_SOLVER_SEED,
        array $constraints = [],
    ): array {
        $serializedConstraints = array_merge(
            $this->serializeTeamCoachConstraints($teamCoaches),
            $this->serializeCoachPlayerMembershipConstraints($coachPlayerMemberships),
            $this->serializePriorityTierConstraints($priorityTiers),
            $this->serializeUnifiedConstraints($constraints, $seasonId),
        );

        return [
            'version' => self::SCHEMA_VERSION,
            'clubId' => $clubId,
            'seasonId' => $seasonId,
            'solverSeed' => $solverSeed,
            'venues' => array_map($this->serializeVenue(...), $venues),
            'teams' => array_map(fn (Team $team): array => $this->serializeTeam($team, $seasonId), $teams),
            'coaches' => array_map($this->serializeCoach(...), $coaches),
            'constraints' => $serializedConstraints,
            'slotTemplates' => array_values(array_filter(
                array_map($this->serializeSlotTemplate(...), $slotTemplates),
                static fn (?array $slotTemplate): bool => null !== $slotTemplate,
            )),
        ];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return array<T>
     */
    private function findByClubSeason(string $className, string $clubId, string $seasonId, ?EntityManagerInterface $entityManager = null): array
    {
        $em = $entityManager ?? $this->entityManager;
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('Entity manager is not available.');
        }

        return $em->getRepository($className)->findBy(
            ['clubId' => $clubId, 'seasonId' => $seasonId],
            ['id' => 'ASC'],
        );
    }

    /** @return array<string, mixed> */
    private function serializeVenue(Venue $venue): array
    {
        return [
            'id' => $venue->getId(),
            'name' => $venue->getName(),
            'isExternal' => $venue->getIsExternal(),
            'color' => $venue->getColor(),
            'latitude' => $venue->getLatitude(),
            'longitude' => $venue->getLongitude(),
            'source' => $venue->getSource(),
            'externalRef' => $venue->getExternalRef(),
            'isActive' => $venue->getIsActive(),
            'parentVenueId' => $venue->getParentVenueId(),
            'availability' => self::DEFAULT_VENUE_AVAILABILITY,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeTeam(Team $team, string $seasonId): array
    {
        $tags = [];
        if ($this->teamTagService instanceof TeamTagService && $this->entityManager instanceof EntityManagerInterface) {
            $this->teamTagService->syncTeamTags($team, $seasonId);
            // Get tags from database
            $tagAssignments = $this->entityManager->getRepository(\App\Entity\TeamTagAssignment::class)->findBy([
                'teamId' => $team->getId(),
                'seasonId' => $seasonId,
            ]);

            foreach ($tagAssignments as $assignment) {
                $tag = $this->entityManager->getRepository(\App\Entity\TeamTag::class)->find($assignment->getTagId());
                if ($tag instanceof \App\Entity\TeamTag) {
                    $tags[] = $tag->getName();
                }
            }
        }

        return [
            'id' => $team->getId(),
            'sportCategoryId' => $team->getSportCategoryId(),
            'priorityTierId' => $team->getPriorityTierId(),
            'name' => $team->getName(),
            'gender' => $team->getGender()?->value,
            'level' => $team->getLevel()?->value,
            'sessionsPerWeek' => $team->getSessionsPerWeek(),
            'minSessionsOverride' => $team->getMinSessionsOverride(),
            'matchDay' => $team->getMatchDay(),
            'forcedVenueId' => $team->getForcedVenueId(),
            'isActive' => $team->getIsActive(),
            'parentTeamId' => $team->getParentTeamId(),
            'tags' => $tags,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeCoach(Coach $coach): array
    {
        return [
            'id' => $coach->getId(),
            'firstName' => $coach->getFirstName(),
            'lastName' => $coach->getLastName(),
            'email' => $coach->getEmail(),
            'phone' => $coach->getPhone(),
            'maxDaysOverride' => $coach->getMaxDaysOverride(),
            'maxDaysOverrideConfirmed' => $coach->getMaxDaysOverrideConfirmed(),
            'acceptableLateMinutes' => $coach->getAcceptableLateMinutes(),
            'isActive' => $coach->getIsActive(),
            'parentCoachId' => $coach->getParentCoachId(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function serializeSlotTemplate(ScheduleSlotTemplate $slotTemplate): ?array
    {
        $lockLevel = $slotTemplate->getLockLevel();
        if (LockLevel::HARD === $lockLevel) {
            return null;
        }

        $pendingConstraintSuggestion = $slotTemplate->getPendingConstraintSuggestion();
        if (LockLevel::SOFT === $lockLevel) {
            $pendingConstraintSuggestion = array_merge(
                ['penalty' => self::SOFT_LOCK_PENALTY, 'reason' => 'Prefer keeping soft-locked slot'],
                $pendingConstraintSuggestion ?? [],
            );
        }

        return [
            'id' => $slotTemplate->getId(),
            'teamId' => $slotTemplate->getTeamId(),
            'venueId' => $slotTemplate->getVenueId(),
            'coachId' => $slotTemplate->getCoachId(),
            'dayOfWeek' => $slotTemplate->getDayOfWeek(),
            'startTime' => $this->formatTime($slotTemplate->getStartTime()),
            'durationMinutes' => $slotTemplate->getDurationMinutes(),
            'lockLevel' => $lockLevel->value,
            'temporaryLock' => $slotTemplate->getTemporaryLock(),
            'temporaryLockFor' => $slotTemplate->getTemporaryLockFor(),
            'temporaryMinSessionsOverride' => $slotTemplate->getTemporaryMinSessionsOverride(),
            'pendingConstraintSuggestion' => $pendingConstraintSuggestion,
        ];
    }

    /**
     * @param array<TeamCoach> $teamCoaches
     *
     * @return array<array<string, mixed>>
     */
    private function serializeTeamCoachConstraints(array $teamCoaches): array
    {
        return array_map(static fn (TeamCoach $teamCoach): array => [
            'id' => \sprintf('team-coach:%s', $teamCoach->getId()),
            'teamId' => $teamCoach->getTeamId(),
            'type' => 'TEAM_COACH',
            'severity' => $teamCoach->getIsRequired() ? 'HARD' : 'SOFT',
            'value' => $teamCoach->getCoachId(),
            'metadata' => [
                'coachId' => $teamCoach->getCoachId(),
                'role' => $teamCoach->getRole(),
                'isRequired' => $teamCoach->getIsRequired(),
            ],
        ], $teamCoaches);
    }

    /**
     * @param array<CoachPlayerMembership> $memberships
     *
     * @return array<array<string, mixed>>
     */
    private function serializeCoachPlayerMembershipConstraints(array $memberships): array
    {
        return array_map(static fn (CoachPlayerMembership $membership): array => [
            'id' => \sprintf('coach-player-unavailability:%s', $membership->getId()),
            'teamId' => $membership->getTeamId(),
            'type' => 'COACH_PLAYER_UNAVAILABILITY',
            'severity' => $membership->getIsActive() ? 'HARD' : 'SOFT',
            'value' => $membership->getCoachId(),
            'metadata' => [
                'coachId' => $membership->getCoachId(),
                'teamId' => $membership->getTeamId(),
                'position' => $membership->getPosition(),
                'isActive' => $membership->getIsActive(),
            ],
        ], $memberships);
    }

    /**
     * @param array<PriorityTier> $priorityTiers
     *
     * @return array<array<string, mixed>>
     */
    private function serializePriorityTierConstraints(array $priorityTiers): array
    {
        return array_map(static fn (PriorityTier $priorityTier): array => [
            'id' => \sprintf('priority-tier:%d', $priorityTier->getId()),
            'teamId' => '*',
            'type' => 'PRIORITY_TIER',
            'severity' => 'SOFT',
            'value' => $priorityTier->getOrToolsWeight(),
            'metadata' => [
                'id' => $priorityTier->getId(),
                'label' => $priorityTier->getLabel(),
                'orToolsWeight' => $priorityTier->getOrToolsWeight(),
                'defaultMinSessions' => $priorityTier->getDefaultMinSessions(),
            ],
        ], $priorityTiers);
    }

    private function formatTime(DateTimeInterface $time): string
    {
        return $time->format('H:i:s');
    }

    /**
     * @param array<Constraint> $constraints
     *
     * @return array<array<string, mixed>>
     */
    private function serializeUnifiedConstraints(array $constraints, string $seasonId): array
    {
        $result = [];

        foreach ($constraints as $constraint) {
            $scope = $constraint->getScope();
            $config = $constraint->getConfig();
            $targetTag = $config['targetTag'] ?? null;

            // Resolve CLUB+targetTag into N TEAM constraints
            if (ConstraintScope::CLUB === $scope && null !== $targetTag && '' !== $targetTag) {
                $teamIds = $this->resolveTagToTeamIds($targetTag, $seasonId);

                foreach ($teamIds as $teamId) {
                    $resolvedConfig = $config;
                    unset($resolvedConfig['targetTag']);

                    $result[] = [
                        'id' => $constraint->getId() . ':' . $teamId,
                        'scope' => ConstraintScope::TEAM->value,
                        'scopeTargetId' => $teamId,
                        'family' => $constraint->getFamily()->value,
                        'ruleType' => $constraint->getRuleType()->value,
                        'name' => $constraint->getName(),
                        'config' => $resolvedConfig,
                        'sortOrder' => $constraint->getSortOrder(),
                        'isActive' => $constraint->getIsActive(),
                    ];
                }

                continue;
            }

            // Pass through as-is (TEAM, COACH, FACILITY, or CLUB without targetTag)
            $result[] = [
                'id' => $constraint->getId(),
                'scope' => $scope->value,
                'scopeTargetId' => $constraint->getScopeTargetId(),
                'family' => $constraint->getFamily()->value,
                'ruleType' => $constraint->getRuleType()->value,
                'name' => $constraint->getName(),
                'config' => $config,
                'sortOrder' => $constraint->getSortOrder(),
                'isActive' => $constraint->getIsActive(),
            ];
        }

        return $result;
    }

    /**
     * Resolve a tag name to the list of team IDs tagged with it in the given season.
     *
     * @return list<string>
     */
    private function resolveTagToTeamIds(string $targetTag, string $seasonId): array
    {
        if (!$this->entityManager instanceof EntityManagerInterface) {
            return [];
        }

        // Find the tag by name
        $tagRepo = $this->entityManager->getRepository(TeamTag::class);
        $tag = $tagRepo->findOneBy(['name' => $targetTag]);

        if (!$tag instanceof TeamTag) {
            return [];
        }

        // Find all team IDs assigned to this tag in this season
        $assignmentRepo = $this->entityManager->getRepository(TeamTagAssignment::class);
        $assignments = $assignmentRepo->findBy([
            'tagId' => $tag->getId(),
            'seasonId' => $seasonId,
        ]);

        $teamIds = [];
        foreach ($assignments as $assignment) {
            $teamIds[] = $assignment->getTeamId();
        }

        return $teamIds;
    }

    /** @param array<object> $entities */
    private function firstString(array $entities, string $method): ?string
    {
        foreach ($entities as $entity) {
            if (!method_exists($entity, $method)) {
                continue;
            }

            $value = $entity->$method();
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }
}
