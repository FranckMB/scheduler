<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\PriorityTier;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamPeriodOverride;
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
    private const SCHEMA_VERSION = '2.1';
    private const DEFAULT_SOLVER_SEED = 42;
    /**
     * Upper bound on the solve budget (seconds), aligned with the engine input
     * schema default (`solver_timeout_seconds` = 650). The engine derives an
     * adaptive timeout from problem size and caps it at this ceiling — this is
     * the maximum a manager can be made to wait, not a fixed solve time.
     */
    private const DEFAULT_SOLVER_TIMEOUT_SECONDS = 650;

    /** @var array<string, array<VenueTrainingSlot>> */
    private array $currentAvailabilitiesByVenue = [];

    /**
     * Period-editable structure: teamId → period sessions-per-week override, set
     * only during an overlay build (serializeTeam reads it, else the seasonal value).
     *
     * @var array<string, int>
     */
    private array $currentSessionOverrides = [];

    /**
     * BCK-04 (assumed by design): the four enrichment deps are nullable ON
     * PURPOSE, not by omission. In production the container always autowires
     * them (no runtime null risk). Nullability enables the **light, DB-free
     * mode**: passing only the logger lets a caller build a payload purely from
     * the entities handed to `buildPayload(...)`, skipping cache / tag-sync /
     * sport-category / venue-slot enrichment via the `instanceof` guards below.
     * The blocking `CrossStack/ContractSchemaTest` relies on this to assert the
     * backend↔engine payload SHAPE without a database. Forcing them non-nullable
     * would only push mocks into that critical test for zero prod benefit.
     */
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

        // Pre-load venue availabilities to avoid N+1 queries in serializeVenue().
        // Base plan only: SEASONAL slots (calendarEntryId IS NULL) — a period's own
        // slots (a gym lent for a window) must never leak into the base generation.
        $availabilitiesByVenue = [];
        if ($this->venueTrainingSlotRepository instanceof VenueTrainingSlotRepository) {
            $rows = $this->venueTrainingSlotRepository->findBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'calendarEntryId' => null]);
            foreach ($rows as $row) {
                $availabilitiesByVenue[$row->getVenueId()][] = $row;
            }
        }

        $this->currentAvailabilitiesByVenue = $availabilitiesByVenue;
        // The base plan has no period session overrides. Reset here (not just at the
        // end of buildForOverlay) so a prior overlay build that threw mid-payload on
        // the long-lived worker can never leak its overrides into a base generation.
        $this->currentSessionOverrides = [];

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
            // Base-plan reservations (calendarEntryId IS NULL) — durable HARD pins.
            reservations: $em->getRepository(Reservation::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'calendarEntryId' => null], ['id' => 'ASC']),
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
     * - closure (fermeture): ALL permanent constraints kept by default (minus those
     *   explicitly disabled) + dated ; each closed venue is expanded into per-team
     *   FACILITY HARD `forbiddenVenueId` constraints — the engine understands
     *   `forbiddenVenueId` (→ forbidden_assignments) but NOT `config.type=venue_closed`.
     * - holiday (reprise): same expansion applies when a dated venue closure is present;
     *   the engine still only consumes `forbiddenVenueId`, so the dated cockpit entry is
     *   rewritten the same way before solve.
     * - holiday (reprise): permanent constraints inherited with a SMART default that
     *   follows the team selection (inheritedPermanents, reprise predicate) + dated.
     *
     * In both, a permanent/dated TEAM-scoped constraint whose team was deactivated for
     * the period is dropped (its team is absent from the payload — no ghost teamId).
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

        // ADR-0002 inv. 5 (lot C2) — les réglages de la période pendent au PLAN. Sans lui,
        // on ne sait pas QUELS réglages appliquer. Échouer bruyamment plutôt que bâtir avec
        // zéro override : ce silence-là génèrerait un planning avec TOUTES les équipes
        // actives, en ignorant la sélection du gestionnaire — un plan faux vaut moins qu'une
        // génération FAILED avec un diagnostic. Inatteignable en pratique : le plan naît du
        // geste (C1) et linkSchedule rattache la version.
        $schedulePlanId = $schedule->getSchedulePlanId();
        if (null === $schedulePlanId) {
            throw new LogicException('Overlay build requires the schedule to be linked to its period plan.');
        }

        $dated = $em->getRepository(Constraint::class)->findBy(['calendarEntryId' => $entry->getId()]);

        // Period-editable structure: sparse per-team overrides for THIS period. Loaded
        // BEFORE the constraint match — the reprise default follows the team selection
        // (a constraint targeting a deactivated team is dropped by default).
        $teamOverrides = $em->getRepository(TeamPeriodOverride::class)->findBy(['schedulePlanId' => $schedulePlanId]);
        $deactivatedTeamIds = [];
        $this->currentSessionOverrides = [];
        foreach ($teamOverrides as $teamOverride) {
            if (!$teamOverride->isActive()) {
                $deactivatedTeamIds[$teamOverride->getTeamId()] = true;
            }
            if (null !== $teamOverride->getSessionsPerWeek()) {
                $this->currentSessionOverrides[$teamOverride->getTeamId()] = $teamOverride->getSessionsPerWeek();
            }
        }

        $constraints = match ($periodType) {
            // Fermeture: inherit ALL permanent constraints (kept by default), minus those
            // the manager explicitly disabled for the window (sparse diff — base untouched).
            CalendarEntryPeriodType::CLOSURE => array_merge(
                $this->inheritedPermanents($clubId, $seasonId, $schedulePlanId, static fn (): bool => true, $em),
                $dated,
            ),
            // Reprise: inherit the socle's permanent constraints with a SMART default that
            // follows the team selection (CLUB/COACH/TEAM kept, FACILITY dropped) — an explicit
            // override deviates ; the post-filter below drops a paused team's TEAM constraint.
            CalendarEntryPeriodType::HOLIDAY => array_merge(
                $this->inheritedPermanents($clubId, $seasonId, $schedulePlanId, static fn (Constraint $c): bool => ConstraintScope::FACILITY !== $c->getScope(), $em),
                $dated,
            ),
            default => throw new LogicException('Overlay build supports only closure and holiday periods.'),
        };

        // Period-editable structure: the overlay's slots are ADDITIVE — the still-valid
        // SEASONAL slots (calendarEntryId NULL) plus this period's OWN slots (a gym lent
        // for the window, calendarEntryId = entry). Fermetures already remove a seasonal
        // gym via the CLOSURE expansion below.
        $availabilitiesByVenue = [];
        if ($this->venueTrainingSlotRepository instanceof VenueTrainingSlotRepository) {
            $slots = array_merge(
                $this->venueTrainingSlotRepository->findBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'calendarEntryId' => null]),
                $this->venueTrainingSlotRepository->findBy(['calendarEntryId' => $entry->getId()]),
            );
            foreach ($slots as $row) {
                $availabilitiesByVenue[$row->getVenueId()][] = $row;
            }
        }
        $this->currentAvailabilitiesByVenue = $availabilitiesByVenue;

        // Deactivated teams (computed above) are dropped from the payload.
        $teams = array_values(array_filter(
            $this->findByClubSeason(Team::class, $clubId, $seasonId, $em),
            static fn (Team $team): bool => !isset($deactivatedTeamIds[$team->getId()]),
        ));

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
            // Overlay reservations: this period's own pins (base ones don't leak in,
            // mirroring how HOLIDAY overlays use only dated constraints).
            reservations: $em->getRepository(Reservation::class)->findBy(['calendarEntryId' => $entry->getId()], ['id' => 'ASC']),
        );

        $this->currentAvailabilitiesByVenue = [];
        $this->currentSessionOverrides = [];

        // Unconditional: the match above throws on every other period type, so CLOSURE and
        // HOLIDAY are the only ones that reach this line — guarding on "either of the two"
        // is a tautology. A new period type gains an arm in that match and MUST decide there
        // whether its dated venue closures need expanding (the engine only ever consumes
        // `forbiddenVenueId`, never `config.type=venue_closed`).
        $payload['constraints'] = array_merge(
            $payload['constraints'],
            $this->expandClosedVenues($dated, $teams),
        );

        // Drop any SERIALIZED TEAM row targeting a team deactivated for the period — an
        // original TEAM constraint, OR a CLUB/tag constraint expanded per-team during
        // serialization (serializeConstraintRow emits scope=TEAM + scopeTargetId=teamId).
        // The team is absent from the payload roster, so a ghost teamId here could turn the
        // solve INFEASIBLE. Filtering the serialized payload (not the entity list) catches
        // the CLUB+targetTag expansion the entity-level scope check would miss.
        if ([] !== $deactivatedTeamIds) {
            $payload['constraints'] = array_values(array_filter(
                $payload['constraints'],
                static fn (mixed $row): bool => !\is_array($row)
                    || ConstraintScope::TEAM->value !== ($row['scope'] ?? null)
                    || !isset($deactivatedTeamIds[(string) ($row['scopeTargetId'] ?? '')]),
            ));
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
        // In-memory (DB-free) builder: no preloaded availabilities, no period
        // overrides. Reset both for symmetry with the DB entry points so a reused
        // instance can never leak a stale map into serializeVenue/serializeTeam.
        $this->currentAvailabilitiesByVenue = [];
        $this->currentSessionOverrides = [];

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
     * @param array<Reservation>           $reservations           persistent team→slot HARD pins (base/overlay)
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
        array $reservations = [],
    ): array {
        $serializedConstraints = array_merge(
            $this->serializeTeamCoachConstraints($teamCoaches),
            $this->serializeCoachPlayerMembershipConstraints($coachPlayerMemberships),
            $this->serializePriorityTierConstraints($priorityTiers),
            $this->serializeUnifiedConstraints($constraints, $seasonId, $clubId, $teams),
        );

        // Reservations feed the SAME engine `slotTemplates` payload (HARD pins) —
        // they are just sourced from the durable Reservation entity instead of the
        // ephemeral, schedule-bound ScheduleSlotTemplate.
        $serializedSlots = array_merge(
            array_filter(
                array_map($this->serializeSlotTemplate(...), $slotTemplates),
                static fn (?array $slotTemplate): bool => null !== $slotTemplate,
            ),
            array_map($this->serializeReservation(...), $reservations),
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
            'slotTemplates' => array_values($serializedSlots),
        ];
    }

    /**
     * Permanent (seasonal) constraints inherited into a period overlay. A
     * ConstraintPeriodOverride row is an EXPLICIT deviation (isActive wins); with no row,
     * $keepByDefault decides. The base plan and the Constraint's own isActive are untouched.
     *   - fermeture: keep all → $keepByDefault = static fn () => true.
     *   - reprise:   FACILITY dropped, rest kept → static fn (c) => FACILITY !== c->getScope().
     * The "a TEAM constraint of a DEACTIVATED team is dropped" rule lives in ONE place — the
     * unconditional post-filter in buildForOverlay — so neither default handles it here.
     *
     * @param callable(Constraint): bool $keepByDefault
     *
     * @return array<Constraint>
     */
    private function inheritedPermanents(string $clubId, string $seasonId, string $schedulePlanId, callable $keepByDefault, EntityManagerInterface $em): array
    {
        $overrides = [];
        foreach ($em->getRepository(ConstraintPeriodOverride::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $override) {
            $overrides[$override->getConstraintId()] = $override->isActive();
        }

        return array_values(array_filter(
            $em->getRepository(Constraint::class)->findPermanentByClubSeason($clubId, $seasonId),
            static fn (Constraint $c): bool => \array_key_exists($c->getId(), $overrides) ? $overrides[$c->getId()] : $keepByDefault($c),
        ));
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
            'sessionsPerWeek' => $this->currentSessionOverrides[$team->getId()] ?? $team->getSessionsPerWeek(),
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
        // ENG-21: no SOFT-lock penalty is built — the engine never consumes it (placebo),
        // and SOFT locks are rejected at the write endpoint. Only NONE/HARD reach here.
        $pendingConstraintSuggestion = $slotTemplate->getPendingConstraintSuggestion();

        return [
            'id' => $slotTemplate->getId(),
            'teamId' => $slotTemplate->getTeamId(),
            'venueId' => $slotTemplate->getVenueId(),
            'coachId' => $slotTemplate->getCoachId(),
            'dayOfWeek' => $slotTemplate->getDayOfWeek(),
            'startTime' => $this->formatTime($slotTemplate->getStartTime()),
            'durationMinutes' => $slotTemplate->getDurationMinutes(),
            'lockLevel' => $slotTemplate->getLockLevel()->value,
            'temporaryLock' => $slotTemplate->getTemporaryLock(),
            'temporaryLockFor' => $slotTemplate->getTemporaryLockFor(),
            'temporaryMinSessionsOverride' => $slotTemplate->getTemporaryMinSessionsOverride(),
            'pendingConstraintSuggestion' => $pendingConstraintSuggestion,
        ];
    }

    /**
     * A Reservation is a HARD team→slot pin — same engine payload shape as a
     * HARD ScheduleSlotTemplate, minus the work-loop fields (temporary locks,
     * pending suggestions) which reservations never carry.
     *
     * @return array<string, mixed>
     */
    private function serializeReservation(Reservation $reservation): array
    {
        return [
            'id' => $reservation->getId(),
            'teamId' => $reservation->getTeamId(),
            'venueId' => $reservation->getVenueId(),
            'coachId' => null,
            'dayOfWeek' => $reservation->getDayOfWeek(),
            'startTime' => $this->formatTime($reservation->getStartTime()),
            'durationMinutes' => $reservation->getDurationMinutes(),
            'lockLevel' => LockLevel::HARD->value,
            'temporaryLock' => false,
            'temporaryLockFor' => null,
            'temporaryMinSessionsOverride' => null,
            'pendingConstraintSuggestion' => null,
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

                // When HARD + a forced venue (preferredVenueId in HARD, or the
                // explicit forcedVenueId "impose" mode): the venue is DEDICATED to
                // the tag → also forbid it for every team NOT in the tag. Both keys
                // force the tag onto the venue engine-side, so exclusivity must
                // cover both, else "impose" would be weaker than HARD "préfère".
                $dedicatedVenueId = $config['forcedVenueId'] ?? $config['preferredVenueId'] ?? null;
                if (ConstraintRuleType::HARD === $constraint->getRuleType() && null !== $dedicatedVenueId) {
                    $tagTeamIdSet = array_flip($teamIds);
                    foreach ($teams as $team) {
                        if (isset($tagTeamIdSet[$team->getId()])) {
                            continue;
                        }
                        $result[] = $this->serializeConstraintRow(
                            $constraint,
                            $constraint->getId() . ':forbidden:' . $team->getId(),
                            $team->getId(),
                            ['forbiddenVenueId' => $dedicatedVenueId],
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
