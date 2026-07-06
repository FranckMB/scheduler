<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ConstraintScope;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Season transition (spec transition-de-saison §2-3, P1): copies the ENTRIES
 * of season N into a fresh N+1 — venues, slots, coaches, teams, links, tag
 * assignments and PERMANENT constraints — never a generated plan (no
 * Schedule/SlotTemplate/Diagnostic and no CalendarEntry: events are re-dated
 * by the P2 guided review). The copy is a starting point, fully editable via
 * the existing wizard; lineage lives in the per-row parent_*_id columns.
 *
 * N+1 starts with baseline/socle null → the existing cockpit gate forces the
 * baseline work. Its `status` is 'draft' (display metadata only — resolution
 * is calendar-derived, see SeasonResolver).
 */
final class SeasonTransitionService
{
    /** Constraint `config` keys that embed entity ids and must be remapped. */
    private const CONFIG_ID_KEYS = [
        'venueId' => 'venues',
        'forbiddenVenueId' => 'venues',
        'preferredVenueId' => 'venues',
        'coachId' => 'coaches',
        'teamId' => 'teams',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SeasonResolver $seasonResolver,
    ) {}

    /**
     * Preconditions: the source must be the CURRENT season, and no season of
     * the next season-year may exist yet (409 carrying the existing id — the
     * caller just switches to it).
     */
    public function transition(Season $source, ?DateTimeImmutable $today = null): Season
    {
        $clubId = $source->getClubId();
        $seasons = $this->seasonResolver->seasonsForClub($clubId);

        $current = SeasonResolver::currentAmong($seasons, $today);
        if (null === $current || $current->getId() !== $source->getId()) {
            throw new ConflictHttpException('Only the current season can be transitioned.');
        }

        $sourceYear = SeasonResolver::seasonYear($source->getStartDate());
        foreach ($seasons as $existing) {
            if (SeasonResolver::seasonYear($existing->getStartDate()) === $sourceYear + 1) {
                throw new SeasonAlreadyTransitionedException($existing->getId());
            }
        }

        // The caller's request may have the season_filter enabled on ANY
        // selected season; the copy reads the SOURCE season explicitly
        // (clubId + seasonId in every query, tenant filter + RLS still on).
        // Not re-enabled: the request ends right after the response.
        $filters = $this->entityManager->getFilters();
        if ($filters->isEnabled('season_filter')) {
            $filters->disable('season_filter');
        }

        return $this->entityManager->wrapInTransaction(fn (): Season => $this->copy($source));
    }

    private function copy(Season $source): Season
    {
        $clubId = $source->getClubId();
        $sourceId = $source->getId();

        $target = new Season;
        $target->setClubId($clubId);
        $target->setName($this->nextName($source->getName()));
        $target->setStartDate($source->getStartDate()->modify('+1 year'));
        $target->setEndDate($source->getEndDate()->modify('+1 year'));
        $target->setStatus('draft');
        $target->setTransitionData([]);
        $this->entityManager->persist($target);

        $venueMap = [];
        foreach ($this->rows(Venue::class, $clubId, $sourceId) as $venue) {
            $copy = new Venue;
            $copy->setClubId($clubId);
            $copy->setSeasonId($target->getId());
            $copy->setName($venue->getName());
            $copy->setIsExternal($venue->getIsExternal());
            $copy->setColor($venue->getColor());
            $copy->setCanSplit($venue->getCanSplit());
            $copy->setLatitude($venue->getLatitude());
            $copy->setLongitude($venue->getLongitude());
            $copy->setSource($venue->getSource());
            $copy->setExternalRef($venue->getExternalRef());
            $copy->setIsActive($venue->getIsActive());
            $copy->setParentVenueId($venue->getId());
            $this->entityManager->persist($copy);
            $venueMap[$venue->getId()] = $copy->getId();
        }

        $slots = 0;
        foreach ($this->rows(VenueTrainingSlot::class, $clubId, $sourceId) as $slot) {
            $newVenueId = $venueMap[$slot->getVenueId()] ?? null;
            if (null === $newVenueId) {
                continue; // dangling venue reference — nothing to attach to.
            }
            $copy = new VenueTrainingSlot;
            $copy->setClubId($clubId);
            $copy->setSeasonId($target->getId());
            $copy->setVenueId($newVenueId);
            $copy->setDayOfWeek($slot->getDayOfWeek());
            $copy->setStartTime($slot->getStartTime());
            $copy->setDurationMinutes($slot->getDurationMinutes());
            $copy->setCapacity($slot->getCapacity());
            $this->entityManager->persist($copy);
            ++$slots;
        }

        $coachMap = [];
        foreach ($this->rows(Coach::class, $clubId, $sourceId) as $coach) {
            $copy = new Coach;
            $copy->setClubId($clubId);
            $copy->setSeasonId($target->getId());
            $copy->setFirstName($coach->getFirstName());
            $copy->setLastName($coach->getLastName());
            $copy->setEmail($coach->getEmail());
            $copy->setPhone($coach->getPhone());
            $copy->setMaxDaysOverride($coach->getMaxDaysOverride());
            $copy->setMaxDaysOverrideConfirmed($coach->getMaxDaysOverrideConfirmed());
            $copy->setAcceptableLateMinutes($coach->getAcceptableLateMinutes());
            $copy->setIsActive($coach->getIsActive());
            $copy->setIsEmployee($coach->isEmployee());
            $copy->setParentCoachId($coach->getId());
            $this->entityManager->persist($copy);
            $coachMap[$coach->getId()] = $copy->getId();
        }

        $teamMap = [];
        foreach ($this->rows(Team::class, $clubId, $sourceId) as $team) {
            $copy = new Team;
            $copy->setClubId($clubId);
            $copy->setSeasonId($target->getId());
            // Club-scoped referentials (category, tier) are shared across seasons.
            $copy->setSportCategoryId($team->getSportCategoryId());
            $copy->setPriorityTierId($team->getPriorityTierId());
            $copy->setTierOrder($team->getTierOrder());
            // Free label, copied as-is — NEVER auto-renamed (spec §6.7).
            $copy->setName($team->getName());
            $copy->setLevel($team->getLevel());
            $copy->setGender($team->getGender());
            $copy->setSize($team->getSize());
            $copy->setSessionsPerWeek($team->getSessionsPerWeek());
            $copy->setMinSessionsOverride($team->getMinSessionsOverride());
            $copy->setMatchDay($team->getMatchDay());
            $copy->setAllowMultipleSessionsPerDay($team->getAllowMultipleSessionsPerDay());
            $forcedVenueId = $team->getForcedVenueId();
            $copy->setForcedVenueId(null !== $forcedVenueId ? ($venueMap[$forcedVenueId] ?? null) : null);
            $copy->setIsActive($team->getIsActive());
            $copy->setParentTeamId($team->getId());
            $this->entityManager->persist($copy);
            $teamMap[$team->getId()] = $copy->getId();
        }

        $teamCoaches = 0;
        foreach ($this->rows(TeamCoach::class, $clubId, $sourceId) as $link) {
            $teamId = $teamMap[$link->getTeamId()] ?? null;
            $coachId = $coachMap[$link->getCoachId()] ?? null;
            if (null === $teamId || null === $coachId) {
                continue;
            }
            $copy = new TeamCoach;
            $copy->setClubId($clubId);
            $copy->setSeasonId($target->getId());
            $copy->setTeamId($teamId);
            $copy->setCoachId($coachId);
            $copy->setRole($link->getRole());
            $copy->setIsRequired($link->getIsRequired());
            $this->entityManager->persist($copy);
            ++$teamCoaches;
        }

        $memberships = 0;
        foreach ($this->rows(CoachPlayerMembership::class, $clubId, $sourceId) as $membership) {
            $teamId = $teamMap[$membership->getTeamId()] ?? null;
            $coachId = $coachMap[$membership->getCoachId()] ?? null;
            if (null === $teamId || null === $coachId) {
                continue;
            }
            $copy = new CoachPlayerMembership;
            $copy->setClubId($clubId);
            $copy->setSeasonId($target->getId());
            $copy->setCoachId($coachId);
            $copy->setTeamId($teamId);
            $copy->setPosition($membership->getPosition());
            $copy->setIsActive($membership->getIsActive());
            $this->entityManager->persist($copy);
            ++$memberships;
        }

        $maps = ['venues' => $venueMap, 'coaches' => $coachMap, 'teams' => $teamMap];
        $constraints = 0;
        // Permanent constraints only: dated ones (calendarEntryId set) belong
        // to season-N periods and die with them.
        foreach ($this->entityManager->getRepository(Constraint::class)->findBy(['clubId' => $clubId, 'seasonId' => $sourceId, 'calendarEntryId' => null]) as $constraint) {
            $copy = new Constraint;
            $copy->setClubId($clubId);
            $copy->setSeasonId($target->getId());
            $copy->setName($constraint->getName());
            $copy->setDescription($constraint->getDescription());
            $copy->setScope($constraint->getScope());
            $copy->setScopeTargetId($this->remapScopeTarget($constraint, $maps));
            $copy->setFamily($constraint->getFamily());
            $copy->setRuleType($constraint->getRuleType());
            $copy->setConfig($this->remapConfig($constraint->getConfig(), $maps));
            $copy->setCreatedBy($constraint->getCreatedBy());
            $copy->setSource($constraint->getSource());
            $copy->setIsActive($constraint->getIsActive());
            $copy->setSortOrder($constraint->getSortOrder());
            $copy->setParentConstraintId($constraint->getId());
            $this->entityManager->persist($copy);
            ++$constraints;
        }

        $counts = [
            'venues' => \count($venueMap),
            'venueTrainingSlots' => $slots,
            'coaches' => \count($coachMap),
            'teams' => \count($teamMap),
            'teamCoaches' => $teamCoaches,
            'coachPlayerMemberships' => $memberships,
            // Completed after the main flush (see the custom-tags pass below).
            'teamTagAssignments' => 0,
            'constraints' => $constraints,
        ];

        $target->setTransitionData([
            'sourceSeasonId' => $sourceId,
            'copiedAt' => (new DateTimeImmutable)->format(\DATE_ATOM),
            'counts' => $counts,
        ]);
        $source->setTransitionData(array_merge($source->getTransitionData(), [
            'transitionedTo' => $target->getId(),
        ]));

        $this->entityManager->flush();

        // Tag assignments AFTER the main flush: persisting the copied teams
        // fires TeamTagSyncListener (postFlush), which wipes and re-derives
        // the SYSTEM tag assignments of each touched team — a copy made
        // before that point is silently deleted. System tags re-derive on
        // their own; only the CUSTOM ones need copying (TeamTag itself is
        // club-scoped and shared across seasons: same tagId).
        $customTagIds = [];
        foreach ($this->entityManager->getRepository(TeamTag::class)->findBy(['clubId' => $clubId, 'isSystem' => false]) as $tag) {
            $customTagIds[$tag->getId()] = true;
        }
        $tagAssignments = 0;
        // TeamTagAssignment has no clubId column — season-scoped only.
        foreach ($this->entityManager->getRepository(TeamTagAssignment::class)->findBy(['seasonId' => $sourceId]) as $assignment) {
            $teamId = $teamMap[$assignment->getTeamId()] ?? null;
            if (null === $teamId || !isset($customTagIds[$assignment->getTagId()])) {
                continue;
            }
            $copy = new TeamTagAssignment;
            $copy->setSeasonId($target->getId());
            $copy->setTeamId($teamId);
            $copy->setTagId($assignment->getTagId());
            $this->entityManager->persist($copy);
            ++$tagAssignments;
        }

        $counts['teamTagAssignments'] = $tagAssignments;
        $target->setTransitionData(array_merge($target->getTransitionData(), ['counts' => $counts]));

        $this->entityManager->flush();

        return $target;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $entityClass
     *
     * @return list<T>
     */
    private function rows(string $entityClass, string $clubId, string $seasonId): array
    {
        return $this->entityManager->getRepository($entityClass)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]);
    }

    /** @param array{venues: array<string,string>, coaches: array<string,string>, teams: array<string,string>} $maps */
    private function remapScopeTarget(Constraint $constraint, array $maps): ?string
    {
        $targetId = $constraint->getScopeTargetId();
        if (null === $targetId) {
            return null;
        }

        return match ($constraint->getScope()) {
            ConstraintScope::TEAM => $maps['teams'][$targetId] ?? null,
            ConstraintScope::COACH => $maps['coaches'][$targetId] ?? null,
            ConstraintScope::FACILITY => $maps['venues'][$targetId] ?? null,
            default => null,
        };
    }

    /**
     * @param array<string, mixed>                                                                            $config
     * @param array{venues: array<string,string>, coaches: array<string,string>, teams: array<string,string>} $maps
     *
     * @return array<string, mixed>
     */
    private function remapConfig(array $config, array $maps): array
    {
        foreach (self::CONFIG_ID_KEYS as $key => $mapName) {
            if (isset($config[$key]) && \is_string($config[$key])) {
                $config[$key] = $maps[$mapName][$config[$key]] ?? $config[$key];
            }
        }

        return $config;
    }

    /** "2025" → "2026" · "2025-2026" → "2026-2027" · any other label: 4-digit years bumped, else kept. */
    private function nextName(string $name): string
    {
        $bumped = preg_replace_callback('/\d{4}/', static fn (array $m): string => (string) ((int) $m[0] + 1), $name);

        return $bumped ?? $name;
    }
}
