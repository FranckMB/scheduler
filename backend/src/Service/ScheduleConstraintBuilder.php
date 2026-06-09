<?php

declare(strict_types=1);

namespace App\Service;

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
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ScheduleConstraintBuilder
{
    private const CACHE_TTL_SECONDS = 14_400;
    private const SCHEMA_VERSION = '1.0';
    private const DEFAULT_SOLVER_SEED = 42;
    private const SOFT_LOCK_PENALTY = 10_000;

    public function __construct(
        private readonly ?EntityManagerInterface $entityManager = null,
        #[Autowire(service: 'cache.schedule')]
        private readonly ?CacheItemPoolInterface $scheduleCachePool = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function buildForClubSeason(string $clubId, string $seasonId, int $solverSeed = self::DEFAULT_SOLVER_SEED): array
    {
        if (!$this->entityManager instanceof EntityManagerInterface || !$this->scheduleCachePool instanceof CacheItemPoolInterface) {
            throw new \LogicException('ScheduleConstraintBuilder requires Doctrine and cache services for club/season builds.');
        }

        $cacheItem = $this->scheduleCachePool->getItem(self::cacheKey($clubId));
        if ($cacheItem->isHit()) {
            $cached = $cacheItem->get();
            if (is_array($cached)) {
                return $cached;
            }
        }

        $payload = $this->buildPayload(
            clubId: $clubId,
            seasonId: $seasonId,
            venues: $this->findByClubSeason(Venue::class, $clubId, $seasonId),
            teams: $this->findByClubSeason(Team::class, $clubId, $seasonId),
            coaches: $this->findByClubSeason(Coach::class, $clubId, $seasonId),
            teamConstraints: $this->findByClubSeason(TeamConstraint::class, $clubId, $seasonId),
            venueAvailabilities: $this->findByClubSeason(VenueAvailability::class, $clubId, $seasonId),
            coachUnavailabilities: $this->findByClubSeason(CoachUnavailability::class, $clubId, $seasonId),
            teamCoaches: $this->findByClubSeason(TeamCoach::class, $clubId, $seasonId),
            coachPlayerMemberships: $this->findByClubSeason(CoachPlayerMembership::class, $clubId, $seasonId),
            slotTemplates: $this->findByClubSeason(ScheduleSlotTemplate::class, $clubId, $seasonId),
            priorityTiers: $this->entityManager->getRepository(PriorityTier::class)->findBy([], ['id' => 'ASC']),
            solverSeed: $solverSeed,
        );

        $cacheItem->set($payload);
        $cacheItem->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->scheduleCachePool->save($cacheItem);

        return $payload;
    }

    /**
     * Legacy in-memory builder kept for existing cross-stack contract coverage.
     *
     * @param array<Venue>          $venues
     * @param array<Team>           $teams
     * @param array<Coach>          $coaches
     * @param array<TeamConstraint> $constraints
     *
     * @return array<string, mixed>
     */
    public function build(array $venues, array $teams, array $coaches, array $constraints): array
    {
        return $this->buildPayload(
            clubId: $this->firstString($venues, 'getClubId')
                ?? $this->firstString($teams, 'getClubId')
                ?? $this->firstString($coaches, 'getClubId')
                ?? $this->firstString($constraints, 'getClubId')
                ?? '',
            seasonId: $this->firstString($venues, 'getSeasonId')
                ?? $this->firstString($teams, 'getSeasonId')
                ?? $this->firstString($coaches, 'getSeasonId')
                ?? $this->firstString($constraints, 'getSeasonId')
                ?? '',
            venues: $venues,
            teams: $teams,
            coaches: $coaches,
            teamConstraints: $constraints,
        );
    }

    /**
     * @param array<Venue>                 $venues
     * @param array<Team>                  $teams
     * @param array<Coach>                 $coaches
     * @param array<TeamConstraint>        $teamConstraints
     * @param array<VenueAvailability>     $venueAvailabilities
     * @param array<CoachUnavailability>   $coachUnavailabilities
     * @param array<TeamCoach>             $teamCoaches
     * @param array<CoachPlayerMembership> $coachPlayerMemberships
     * @param array<ScheduleSlotTemplate>  $slotTemplates
     * @param array<PriorityTier>          $priorityTiers
     *
     * @return array<string, mixed>
     */
    public function buildPayload(
        string $clubId,
        string $seasonId,
        array $venues = [],
        array $teams = [],
        array $coaches = [],
        array $teamConstraints = [],
        array $venueAvailabilities = [],
        array $coachUnavailabilities = [],
        array $teamCoaches = [],
        array $coachPlayerMemberships = [],
        array $slotTemplates = [],
        array $priorityTiers = [],
        int $solverSeed = self::DEFAULT_SOLVER_SEED,
    ): array {
        return [
            'version' => self::SCHEMA_VERSION,
            'clubId' => $clubId,
            'seasonId' => $seasonId,
            'solverSeed' => $solverSeed,
            'venues' => array_map($this->serializeVenue(...), $venues),
            'teams' => array_map($this->serializeTeam(...), $teams),
            'coaches' => array_map($this->serializeCoach(...), $coaches),
            'constraints' => array_merge(
                $this->serializeTeamConstraints($teamConstraints),
                $this->serializeVenueAvailabilityConstraints($venueAvailabilities),
                $this->serializeCoachUnavailabilityConstraints($coachUnavailabilities),
                $this->serializeTeamCoachConstraints($teamCoaches),
                $this->serializeCoachPlayerMembershipConstraints($coachPlayerMemberships),
                $this->serializePriorityTierConstraints($priorityTiers),
                $this->serializeMaxDaysPerWeekConstraints($venueAvailabilities),
            ),
            'slotTemplates' => array_values(array_filter(
                array_map($this->serializeSlotTemplate(...), $slotTemplates),
                static fn (?array $slotTemplate): bool => null !== $slotTemplate,
            )),
        ];
    }

    public static function cacheKey(string $clubId): string
    {
        return sprintf('club:%s:schedule_input', $clubId);
    }

    /**
     * @param array<VenueAvailability> $venueAvailabilities
     *
     * @return array<string, int>
     */
    public function calculateMaxDaysPerWeek(array $venueAvailabilities): array
    {
        $daysByVenue = [];
        foreach ($venueAvailabilities as $availability) {
            $daysByVenue[$availability->getVenueId()][$availability->getDayOfWeek()] = true;
        }

        $maxDays = [];
        foreach ($daysByVenue as $venueId => $days) {
            $maxDays[$venueId] = count($days);
        }

        ksort($maxDays);

        return $maxDays;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return array<T>
     */
    private function findByClubSeason(string $className, string $clubId, string $seasonId): array
    {
        if (!$this->entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('Entity manager is not available.');
        }

        return $this->entityManager->getRepository($className)->findBy(
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
        ];
    }

    /** @return array<string, mixed> */
    private function serializeTeam(Team $team): array
    {
        return [
            'id' => $team->getId(),
            'sportCategoryId' => $team->getSportCategoryId(),
            'priorityTierId' => $team->getPriorityTierId(),
            'name' => $team->getName(),
            'gender' => $team->getGender(),
            'sessionsPerWeek' => $team->getSessionsPerWeek(),
            'minSessionsOverride' => $team->getMinSessionsOverride(),
            'matchDay' => $team->getMatchDay(),
            'forcedVenueId' => $team->getForcedVenueId(),
            'isActive' => $team->getIsActive(),
            'parentTeamId' => $team->getParentTeamId(),
            'ffbbTeamId' => $team->getFfbbTeamId(),
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
     * @param array<TeamConstraint> $teamConstraints
     *
     * @return array<array<string, mixed>>
     */
    private function serializeTeamConstraints(array $teamConstraints): array
    {
        return array_map(static fn (TeamConstraint $constraint): array => [
            'id' => $constraint->getId(),
            'teamId' => $constraint->getTeamId(),
            'type' => $constraint->getType(),
            'severity' => null,
            'value' => null,
            'metadata' => array_filter([
                'dayOfWeek' => $constraint->getDayOfWeek(),
                'startTime' => self::formatNullableTime($constraint->getStartTime()),
                'endTime' => self::formatNullableTime($constraint->getEndTime()),
                'venueId' => $constraint->getVenueId(),
                'reason' => $constraint->getReason(),
                'createdBy' => $constraint->getCreatedBy(),
                'sourceOccurrenceId' => $constraint->getSourceOccurrenceId(),
            ], static fn (mixed $value): bool => null !== $value),
        ], $teamConstraints);
    }

    /**
     * @param array<VenueAvailability> $venueAvailabilities
     *
     * @return array<array<string, mixed>>
     */
    private function serializeVenueAvailabilityConstraints(array $venueAvailabilities): array
    {
        return array_map(fn (VenueAvailability $availability): array => [
            'id' => sprintf('venue-availability:%s', $availability->getId()),
            'teamId' => '*',
            'type' => 'VENUE_AVAILABILITY',
            'severity' => 'HARD',
            'value' => true,
            'metadata' => [
                'venueId' => $availability->getVenueId(),
                'dayOfWeek' => $availability->getDayOfWeek(),
                'startTime' => $this->formatTime($availability->getStartTime()),
                'endTime' => $this->formatTime($availability->getEndTime()),
            ],
        ], $venueAvailabilities);
    }

    /**
     * @param array<CoachUnavailability> $coachUnavailabilities
     *
     * @return array<array<string, mixed>>
     */
    private function serializeCoachUnavailabilityConstraints(array $coachUnavailabilities): array
    {
        return array_map(fn (CoachUnavailability $unavailability): array => [
            'id' => sprintf('coach-unavailability:%s', $unavailability->getId()),
            'teamId' => '*',
            'type' => 'COACH_UNAVAILABILITY',
            'severity' => 'HARD',
            'value' => true,
            'metadata' => [
                'coachId' => $unavailability->getCoachId(),
                'dayOfWeek' => $unavailability->getDayOfWeek(),
                'startTime' => self::formatNullableTime($unavailability->getStartTime()),
                'endTime' => self::formatNullableTime($unavailability->getEndTime()),
            ],
        ], $coachUnavailabilities);
    }

    /**
     * @param array<TeamCoach> $teamCoaches
     *
     * @return array<array<string, mixed>>
     */
    private function serializeTeamCoachConstraints(array $teamCoaches): array
    {
        return array_map(static fn (TeamCoach $teamCoach): array => [
            'id' => sprintf('team-coach:%s', $teamCoach->getId()),
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
            'id' => sprintf('coach-player-unavailability:%s', $membership->getId()),
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
            'id' => sprintf('priority-tier:%d', $priorityTier->getId()),
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

    /**
     * @param array<VenueAvailability> $venueAvailabilities
     *
     * @return array<array<string, mixed>>
     */
    private function serializeMaxDaysPerWeekConstraints(array $venueAvailabilities): array
    {
        $constraints = [];
        foreach ($this->calculateMaxDaysPerWeek($venueAvailabilities) as $venueId => $maxDaysPerWeek) {
            $constraints[] = [
                'id' => sprintf('max-days-per-week:%s', $venueId),
                'teamId' => '*',
                'type' => 'MAX_DAYS_PER_WEEK',
                'severity' => 'HARD',
                'value' => $maxDaysPerWeek,
                'metadata' => [
                    'venueId' => $venueId,
                    'maxDaysPerWeek' => $maxDaysPerWeek,
                ],
            ];
        }

        return $constraints;
    }

    private function formatTime(\DateTimeInterface $time): string
    {
        return $time->format('H:i:s');
    }

    private static function formatNullableTime(?\DateTimeInterface $time): ?string
    {
        return $time?->format('H:i:s');
    }

    /** @param array<object> $entities */
    private function firstString(array $entities, string $method): ?string
    {
        foreach ($entities as $entity) {
            if (!method_exists($entity, $method)) {
                continue;
            }

            $value = $entity->$method();
            if (is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }
}
