<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Repository\VenueTrainingSlotRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ScheduleConstraintBuilder
{
    private const CACHE_TTL_SECONDS = 14_400;
    private const SCHEMA_VERSION = '2.0';
    private const DEFAULT_SOLVER_SEED = 42;
    private const SOFT_LOCK_PENALTY = 10_000;

    /** @var array<string, array<VenueTrainingSlot>> */
    private array $currentAvailabilitiesByVenue = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?EntityManagerInterface $entityManager = null,
        #[Autowire(service: 'cache.schedule')]
        private readonly ?CacheItemPoolInterface $scheduleCachePool = null,
        private readonly ?TeamTagService $teamTagService = null,
        private readonly ?VenueTrainingSlotRepository $venueTrainingSlotRepository = null,
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

        $constraints = $em->getRepository(Constraint::class)->findByClubSeason($clubId, $seasonId);

        // Pre-load venue availabilities to avoid N+1 queries in serializeVenue()
        $availabilitiesByVenue = [];
        if ($this->venueTrainingSlotRepository instanceof VenueTrainingSlotRepository) {
            $rows = $this->venueTrainingSlotRepository->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]);
            foreach ($rows as $row) {
                $availabilitiesByVenue[$row->getVenueId()][] = $row;
            }
        }

        $this->currentAvailabilitiesByVenue = $availabilitiesByVenue;

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

        $this->currentAvailabilitiesByVenue = [];

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
     * @param array<Venue>                 $venues
     * @param array<Team>                  $teams
     * @param array<Coach>                 $coaches
     * @param array<TeamCoach>             $teamCoaches
     * @param array<CoachPlayerMembership> $coachPlayerMemberships
     * @param array<ScheduleSlotTemplate>  $slotTemplates
     * @param array<PriorityTier>          $priorityTiers
     * @param array<Constraint>            $constraints
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
     * @param array<Venue>                 $venues
     * @param array<Team>                  $teams
     * @param array<Coach>                 $coaches
     * @param array<TeamCoach>             $teamCoaches
     * @param array<CoachPlayerMembership> $coachPlayerMemberships
     * @param array<ScheduleSlotTemplate>  $slotTemplates
     * @param array<PriorityTier>          $priorityTiers
     * @param array<Constraint>            $constraints
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
            $this->serializeUnifiedConstraints($constraints, $seasonId, $clubId, $teams),
        );

        return [
            'version' => self::SCHEMA_VERSION,
            'clubId' => $clubId,
            'seasonId' => $seasonId,
            'solverSeed' => $solverSeed,
            'solverTimeoutSeconds' => 300,
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
            'trainingSlots' => $this->buildTrainingSlots($this->currentAvailabilitiesByVenue[$venue->getId()] ?? []),
        ];
    }

    /**
     * @param array<VenueTrainingSlot> $slots
     *
     * @return array<int, array{dayOfWeek: int, startTime: string, durationMinutes: int, capacity: int}>
     */
    private function buildTrainingSlots(array $slots): array
    {
        if ([] === $slots) {
            return [];
        }

        $result = [];
        foreach ($slots as $slot) {
            $result[] = [
                'dayOfWeek' => $slot->getDayOfWeek(),
                'startTime' => $slot->getStartTime()->format('H:i'),
                'durationMinutes' => $slot->getDurationMinutes(),
                'capacity' => $slot->getCapacity(),
            ];
        }

        usort($result, static fn (array $a, array $b): int => $a['dayOfWeek'] <=> $b['dayOfWeek'] ?: strcmp($a['startTime'], $b['startTime']));

        return $result;
    }

    /** @return array<string, mixed> */
    private function serializeTeam(Team $team, string $seasonId): array
    {
        $tags = [];
        $sportCategory = null;
        if ($this->entityManager instanceof EntityManagerInterface) {
            $sportCategory = $this->entityManager->getRepository(SportCategory::class)->find($team->getSportCategoryId());
        }
        if ($this->teamTagService instanceof TeamTagService && $this->entityManager instanceof EntityManagerInterface) {
            $this->teamTagService->syncTeamTags($team, $seasonId);
            // Get tags from database
            $tagAssignments = $this->entityManager->getRepository(TeamTagAssignment::class)->findBy([
                'teamId' => $team->getId(),
                'seasonId' => $seasonId,
            ]);

            foreach ($tagAssignments as $assignment) {
                $tag = $this->entityManager->getRepository(TeamTag::class)->find($assignment->getTagId());
                if ($tag instanceof TeamTag) {
                    $tags[] = $tag->getName();
                }
            }
        }

        return [
            'id' => $team->getId(),
            'sportCategoryId' => $team->getSportCategoryId(),
            'ageMin' => $sportCategory?->getAgeMin(),
            'ageMax' => $sportCategory?->getAgeMax(),
            'priorityTierId' => $team->getPriorityTierId(),
            'name' => $team->getName(),
            'gender' => $team->getGender()?->value,
            'level' => $team->getLevel()?->value,
            'sessionsPerWeek' => $team->getSessionsPerWeek(),
            'minSessionsOverride' => $team->getMinSessionsOverride(),
            'matchDay' => $team->getMatchDay(),
            'allowMultipleSessionsPerDay' => $team->getAllowMultipleSessionsPerDay(),
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

    /** @return array<string, mixed> */
    private function serializeSlotTemplate(ScheduleSlotTemplate $slotTemplate): array
    {
        $lockLevel = $slotTemplate->getLockLevel();

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
     * @param array<Team>       $teams
     *
     * @return array<array<string, mixed>>
     */
    private function serializeUnifiedConstraints(array $constraints, string $seasonId, string $clubId, array $teams = []): array
    {
        $result = [];

        foreach ($constraints as $constraint) {
            $scope = $constraint->getScope();
            $config = $constraint->getConfig();
            $targetTag = $config['targetTag'] ?? null;

            // Resolve CLUB+targetTag into N TEAM constraints
            if (ConstraintScope::CLUB === $scope && null !== $targetTag && '' !== $targetTag) {
                $teamIds = $this->resolveTagToTeamIds($targetTag, $seasonId, $clubId);

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

                // When HARD + preferredVenueId: also forbid the venue for all teams NOT in the tag
                if (ConstraintRuleType::HARD === $constraint->getRuleType() && isset($config['preferredVenueId'])) {
                    $tagTeamIdSet = array_flip($teamIds);
                    foreach ($teams as $team) {
                        if (isset($tagTeamIdSet[$team->getId()])) {
                            continue;
                        }
                        $result[] = [
                            'id' => $constraint->getId() . ':forbidden:' . $team->getId(),
                            'scope' => ConstraintScope::TEAM->value,
                            'scopeTargetId' => $team->getId(),
                            'family' => $constraint->getFamily()->value,
                            'ruleType' => ConstraintRuleType::HARD->value,
                            'name' => $constraint->getName() . ' (interdit hors tag)',
                            'config' => ['forbiddenVenueId' => $config['preferredVenueId']],
                            'sortOrder' => $constraint->getSortOrder(),
                            'isActive' => $constraint->getIsActive(),
                        ];
                    }
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
    private function resolveTagToTeamIds(string $targetTag, string $seasonId, string $clubId): array
    {
        if (!$this->entityManager instanceof EntityManagerInterface) {
            return [];
        }

        // Find the tag by name
        $tagRepo = $this->entityManager->getRepository(TeamTag::class);
        $tag = $tagRepo->findOneBy(['name' => $targetTag, 'clubId' => $clubId]);

        if (!$tag instanceof TeamTag) {
            $this->logger->warning(
                "Tag '{$targetTag}' not found for club {$clubId} — constraint will be ignored.",
                ['targetTag' => $targetTag, 'clubId' => $clubId, 'seasonId' => $seasonId],
            );

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
