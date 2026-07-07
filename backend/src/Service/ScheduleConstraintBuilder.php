<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
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
    /**
     * Upper bound on the solve budget (seconds), aligned with the engine input
     * schema default (`solver_timeout_seconds` = 650). The engine derives an
     * adaptive timeout from problem size and caps it at this ceiling — this is
     * the maximum a manager can be made to wait, not a fixed solve time.
     */
    private const DEFAULT_SOLVER_TIMEOUT_SECONDS = 650;
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

        // Base plan only: dated constraints (attached to a CalendarEntry period)
        // are excluded from generation. See accueil-cockpit-temporel.md §9ter.c.
        $constraints = $em->getRepository(Constraint::class)->findPermanentByClubSeason($clubId, $seasonId);

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
            // Base plan only: exclude OVERLAY schedules' slot templates (palier B),
            // otherwise an overlay's locks would leak into the base generation.
            slotTemplates: $this->findBaseSlotTemplates($clubId, $seasonId, $em),
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
     * Build the engine payload for a period OVERLAY (palier B). BYPASSES the
     * schedule-input cache (overlays are rare; the base key stays clean).
     *
     * - closure (additive): permanent + dated constraints, and each closed venue
     *   is expanded into per-team FACILITY HARD `forbiddenVenueId` constraints —
     *   the engine understands `forbiddenVenueId` (→ forbidden_assignments) but
     *   NOT `config.type=venue_closed`, so the expansion carries the semantics.
     * - holiday (partial replacement): dated constraints only, full structure.
     *
     * slotTemplates are scoped to THIS overlay schedule (its own locks), not the
     * base plan's — the base build (buildForClubSeason) is untouched.
     *
     * @return array<string, mixed>
     */
    public function buildForOverlay(Schedule $schedule, CalendarEntry $entry): array
    {
        $em = $this->entityManager;
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('ScheduleConstraintBuilder requires Doctrine for overlay builds.');
        }

        $clubId = $schedule->getClubId();
        $seasonId = $schedule->getSeasonId();
        $periodType = $entry->getPeriodType();

        $dated = $em->getRepository(Constraint::class)->findBy(['calendarEntryId' => $entry->getId()]);
        $constraints = match ($periodType) {
            CalendarEntryPeriodType::CLOSURE => array_merge(
                $em->getRepository(Constraint::class)->findPermanentByClubSeason($clubId, $seasonId),
                $dated,
            ),
            CalendarEntryPeriodType::HOLIDAY => $dated,
            default => throw new LogicException('Overlay build supports only closure and holiday periods.'),
        };

        // Preload venue availabilities for serializeVenue() (same as base build).
        $availabilitiesByVenue = [];
        if ($this->venueTrainingSlotRepository instanceof VenueTrainingSlotRepository) {
            foreach ($this->venueTrainingSlotRepository->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $row) {
                $availabilitiesByVenue[$row->getVenueId()][] = $row;
            }
        }
        $this->currentAvailabilitiesByVenue = $availabilitiesByVenue;

        $teams = $this->findByClubSeason(Team::class, $clubId, $seasonId, $em);

        $payload = $this->buildPayload(
            clubId: $clubId,
            seasonId: $seasonId,
            venues: $this->findByClubSeason(Venue::class, $clubId, $seasonId, $em),
            teams: $teams,
            coaches: $this->findByClubSeason(Coach::class, $clubId, $seasonId, $em),
            teamCoaches: $this->findByClubSeason(TeamCoach::class, $clubId, $seasonId, $em),
            coachPlayerMemberships: $this->findByClubSeason(CoachPlayerMembership::class, $clubId, $seasonId, $em),
            // Overlay's OWN slot templates (its work-loop locks), not the base plan's.
            slotTemplates: $em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $schedule->getId()], ['id' => 'ASC']),
            priorityTiers: $em->getRepository(PriorityTier::class)->findBy([], ['id' => 'ASC']),
            solverSeed: $schedule->getSolverSeed(),
            constraints: $constraints,
        );

        $this->currentAvailabilitiesByVenue = [];

        if (CalendarEntryPeriodType::CLOSURE === $periodType) {
            $payload['constraints'] = array_merge(
                $payload['constraints'],
                $this->expandClosedVenues($dated, $teams),
            );
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
            'solverTimeoutSeconds' => self::DEFAULT_SOLVER_TIMEOUT_SECONDS,
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
     * Expand each closed venue (scopeTargetId of the entry's active FACILITY
     * dated constraints — same convention as CalendarEntryConflictsController)
     * into per-team FACILITY HARD `forbiddenVenueId` constraints the engine
     * honors (parse_v2_constraints → forbidden_assignments).
     *
     * @param array<Constraint> $dated
     * @param array<Team>       $teams
     *
     * @return list<array<string, mixed>>
     */
    private function expandClosedVenues(array $dated, array $teams): array
    {
        $closedVenueIds = [];
        foreach ($dated as $constraint) {
            if (ConstraintFamily::FACILITY === $constraint->getFamily() && $constraint->getIsActive() && null !== $constraint->getScopeTargetId()) {
                $closedVenueIds[$constraint->getScopeTargetId()] = true;
            }
        }

        $expanded = [];
        foreach (array_keys($closedVenueIds) as $venueId) {
            foreach ($teams as $team) {
                $expanded[] = [
                    'id' => \sprintf('overlay-closed:%s:%s', $venueId, $team->getId()),
                    'scope' => ConstraintScope::TEAM->value,
                    'scopeTargetId' => $team->getId(),
                    'family' => ConstraintFamily::FACILITY->value,
                    'ruleType' => ConstraintRuleType::HARD->value,
                    'name' => 'Salle fermée (période)',
                    'config' => ['forbiddenVenueId' => $venueId],
                    'sortOrder' => 0,
                    'isActive' => true,
                ];
            }
        }

        return $expanded;
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

    /**
     * Slot templates of the club/season that belong to a BASE schedule (not an
     * overlay). Excludes overlay slots — and orphan slots whose schedule row is
     * gone — from base-plan generation. See palier B.
     *
     * @return array<ScheduleSlotTemplate>
     */
    private function findBaseSlotTemplates(string $clubId, string $seasonId, EntityManagerInterface $em): array
    {
        return $em->getRepository(ScheduleSlotTemplate::class)->createQueryBuilder('s')
            ->andWhere('s.clubId = :clubId')
            ->andWhere('s.seasonId = :seasonId')
            ->andWhere('s.scheduleId IN (SELECT sch.id FROM ' . Schedule::class . ' sch WHERE sch.clubId = :clubId AND sch.seasonId = :seasonId AND sch.calendarEntryId IS NULL)')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
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
            'trainingSlots' => $this->buildTrainingSlots($this->currentAvailabilitiesByVenue[$venue->getId()] ?? [], $venue->getCanSplit()),
        ];
    }

    /**
     * @param array<VenueTrainingSlot> $slots
     *
     * @return array<int, array{dayOfWeek: int, startTime: string, durationMinutes: int, capacity: int}>
     */
    private function buildTrainingSlots(array $slots, bool $canSplit): array
    {
        if ([] === $slots) {
            return [];
        }

        $result = [];
        foreach ($slots as $slot) {
            // Divisibility is a venue property: an indivisible venue (single
            // court) can host at most one team per slot, whatever the slot's
            // stored capacity. Only a splittable venue may expose capacity > 1.
            $capacity = $canSplit ? $slot->getCapacity() : 1;
            $result[] = [
                'dayOfWeek' => $slot->getDayOfWeek(),
                'startTime' => $slot->getStartTime()->format('H:i'),
                'durationMinutes' => $slot->getDurationMinutes(),
                'capacity' => $capacity,
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
            'isEmployee' => $coach->isEmployee(),
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
        // orToolsWeight is intentionally NOT sent: the solver enforces tier
        // priority with fixed hardcoded weights (S=10000/A=1000/B=100/C=10/D=1),
        // so a per-tier weight would be accepted then ignored. The engine reads
        // only metadata.id + metadata.defaultMinSessions from this constraint.
        return array_map(static fn (PriorityTier $priorityTier): array => [
            'id' => \sprintf('priority-tier:%d', $priorityTier->getId()),
            'teamId' => '*',
            'type' => 'PRIORITY_TIER',
            'severity' => 'SOFT',
            'value' => null,
            'metadata' => [
                'id' => $priorityTier->getId(),
                'label' => $priorityTier->getLabel(),
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

                // An empty resolution (typo, tags not re-applied after a season
                // rollover) must be a NO-OP: running the "forbidden outside the
                // tag" loop below with zero tagged teams would ban the venue
                // for EVERY team of the club (audit review).
                if ([] === $teamIds) {
                    $this->logger->warning('Tag "{tag}" resolves to no team — constraint {id} skipped.', [
                        'tag' => $targetTag,
                        'id' => $constraint->getId(),
                    ]);

                    continue;
                }

                foreach ($teamIds as $teamId) {
                    $resolvedConfig = $config;
                    unset($resolvedConfig['targetTag']);

                    $result[] = $this->serializeConstraintRow($constraint, $constraint->getId() . ':' . $teamId, $teamId, $resolvedConfig);
                }

                // When HARD + preferredVenueId: also forbid the venue for all teams NOT in the tag
                if (ConstraintRuleType::HARD === $constraint->getRuleType() && isset($config['preferredVenueId'])) {
                    $tagTeamIdSet = array_flip($teamIds);
                    foreach ($teams as $team) {
                        if (isset($tagTeamIdSet[$team->getId()])) {
                            continue;
                        }
                        $result[] = $this->serializeConstraintRow(
                            $constraint,
                            $constraint->getId() . ':forbidden:' . $team->getId(),
                            $team->getId(),
                            ['forbiddenVenueId' => $config['preferredVenueId']],
                            name: $constraint->getName() . ' (interdit hors tag)',
                            ruleType: ConstraintRuleType::HARD->value,
                        );
                    }
                }

                continue;
            }

            // Resolve a club-wide TIME/DAY/FACILITY rule ("Toutes les équipes")
            // into one TEAM constraint per team: the engine only applies these
            // families to a team target — a CLUB-scope one was a silent no-op
            // (audit P0.1, dead "all teams" cell). Same expansion pattern as
            // CLUB+targetTag above. COACH_AVAILABILITY is coach-scoped and
            // FACILITY_CAPACITY venue-keyed → both pass through untouched.
            $expandableFamilies = [ConstraintFamily::TIME, ConstraintFamily::DAY, ConstraintFamily::FACILITY];
            if (ConstraintScope::CLUB === $scope && \in_array($constraint->getFamily(), $expandableFamilies, true)) {
                foreach ($teams as $team) {
                    $result[] = $this->serializeConstraintRow($constraint, $constraint->getId() . ':' . $team->getId(), $team->getId(), $config);
                }

                continue;
            }

            // Pass through as-is (TEAM, COACH, or CLUB variants handled above)
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
     * One serialized TEAM-scope constraint row (the shared shape of the three
     * expansion paths — tag, forbidden-outside-tag, club-wide).
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function serializeConstraintRow(Constraint $constraint, string $id, string $teamId, array $config, ?string $name = null, ?string $ruleType = null): array
    {
        return [
            'id' => $id,
            'scope' => ConstraintScope::TEAM->value,
            'scopeTargetId' => $teamId,
            'family' => $constraint->getFamily()->value,
            'ruleType' => $ruleType ?? $constraint->getRuleType()->value,
            'name' => $name ?? $constraint->getName(),
            'config' => $config,
            'sortOrder' => $constraint->getSortOrder(),
            'isActive' => $constraint->getIsActive(),
        ];
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
