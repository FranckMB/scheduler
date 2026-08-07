<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueTrainingSlot;
use App\Enum\ConstraintScope;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Season transition (spec transition-de-saison §2-3, P1): copies the ENTRIES
 * of season N into a fresh N+1 — venues, slots, coaches, teams, links and
 * PERMANENT constraints — never a generated plan (no
 * Schedule/SlotTemplate/Diagnostic and no CalendarEntry: events are re-dated
 * by the P2 guided review). System team tags re-derive on their own via
 * TeamTagSyncListener; custom tags are not carried (see the flush note below).
 * The copy is a starting point, fully editable via the existing wizard;
 * lineage lives in the per-row parent_*_id columns.
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
        private readonly ClockInterface $clock,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /**
     * Preconditions: the source must be the CURRENT season, and no season of
     * the next season-year may exist yet (409 carrying the existing id — the
     * caller just switches to it).
     */
    public function transition(Season $source, ?DateTimeImmutable $today = null): Season
    {
        // Resolve "today" once from the clock (dev simulator can pin it) and
        // thread it to BOTH the current-season guard AND the target-year anchor
        // in copy() — otherwise a rehearsal transitions to a real-time year.
        $today ??= DateTimeImmutable::createFromInterface($this->clock->now());
        $clubId = $source->getClubId();

        $current = $this->seasonResolver->currentSeason($clubId, $today);
        if (!$current instanceof Season || $current->getId() !== $source->getId()) {
            throw new ConflictHttpException('Only the current season can be transitioned.');
        }

        // P1-5 (décision fondateur 2026-08-04) — l'abonnement se paie PAR SAISON :
        // la bascule vers N+1 exige que N+1 soit réglée. Le gate est LA BASCULE,
        // pas la génération — générer sa saison suivante dès mai puis résilier
        // était la fuite. Marqueur avancé par l'action support « Marquer la
        // saison suivante payée » (paiement en ligne : P1-3). Fail-closed : un
        // marqueur absent (club d'avant le backfill) bloque aussi.
        // MÊME année cible que copy() — un club dormant bascule vers l'année de
        // « demain », pas vers source+1 : le gate doit exiger le paiement de la
        // saison qui sera réellement créée.
        $targetYear = max(
            SeasonResolver::seasonYear($source->getStartDate()) + 1,
            SeasonResolver::seasonYear($today) + 1,
        );
        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        // P2-4 (fondateur 2026-08-07) — un club de DÉMONSTRATION a un abonnement
        // illimité : la bascule de saison se montre en rendez-vous (souvent sous
        // horloge simulée), jamais bloquée par un paiement qui n'existe pas.
        $isDemo = $club instanceof Club && $club->isDemo();
        if (!$club instanceof Club || (!$isDemo && ($club->getPaidSeasonYear() ?? \PHP_INT_MIN) < $targetYear)) {
            throw new ConflictHttpException(\sprintf('La saison %d-%d n\'est pas réglée — la bascule est réservée aux saisons payées.', $targetYear, $targetYear + 1));
        }

        // The caller's request may have the season_filter enabled on ANY
        // selected season; the copy reads the SOURCE season explicitly
        // (clubId + seasonId in every query, tenant filter + RLS still on).
        // Not re-enabled: the request ends right after the response.
        $filters = $this->entityManager->getFilters();
        if ($filters->isEnabled('season_filter')) {
            $filters->disable('season_filter');
        }

        return $this->entityManager->wrapInTransaction(function () use ($source, $clubId, $today): Season {
            // Serialize concurrent transitions of the same club: without this,
            // two requests both pass the successor check and fork the club into
            // duplicate N+1 seasons (no DB unique on club_id + season-year).
            $this->entityManager->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtext(:club))',
                ['club' => 'season-transition:' . $clubId],
            );

            // Re-check successor existence INSIDE the lock (the other request
            // may have committed its N+1 while we waited on the lock).
            $sourceYear = SeasonResolver::seasonYear($source->getStartDate());
            foreach ($this->seasonResolver->seasonsForClub($clubId) as $existing) {
                if (SeasonResolver::seasonYear($existing->getStartDate()) === $sourceYear + 1) {
                    throw new SeasonAlreadyTransitionedException($existing->getId());
                }
            }

            return $this->copy($source, $today);
        });
    }

    private function copy(Season $source, ?DateTimeImmutable $today = null): Season
    {
        $clubId = $source->getClubId();
        $sourceId = $source->getId();

        // Shift the source window by whole years so the target lands in the
        // NEXT season-year (source-year + 1). Anchored to today so a dormant
        // club whose stale current season is years old still gets a next
        // season that is actually upcoming, not another past one.
        $today ??= DateTimeImmutable::createFromInterface($this->clock->now());
        $targetYear = max(
            SeasonResolver::seasonYear($source->getStartDate()) + 1,
            SeasonResolver::seasonYear($today) + 1,
        );
        $yearsToAdd = $targetYear - SeasonResolver::seasonYear($source->getStartDate());
        $shift = \sprintf('+%d year', $yearsToAdd);

        $target = new Season;
        $target->setClubId($clubId);
        $target->setName($this->nextName($source->getName(), $yearsToAdd));
        $target->setStartDate($source->getStartDate()->modify($shift));
        $target->setEndDate($source->getEndDate()->modify($shift));
        $target->setStatus('draft');
        $target->setTransitionData([]);
        $this->entityManager->persist($target);

        // ADR-0002 Lot A: the copied season starts with its empty SEASON plan.
        $this->schedulePlanProvisioner->ensureSeasonPlan($target);

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
            // Seule la structure PARTAGÉE se transmet (ancre nulle, inv. 6). Un créneau
            // PRÊTÉ appartient à un plan de la saison source — « la mairie me prête ce
            // gymnase POUR cet ajustement d'octobre » n'a aucun sens en N+1, et la copie
            // ne posant jamais l'ancre, il deviendrait un créneau SAISONNIER PERMANENT du
            // club : le socle de la nouvelle saison hériterait d'un gymnase prêté une
            // semaine. Planning plausible, et faux. (Défaut préexistant : la copie ne
            // portait pas non plus l'ancien calendarEntryId.)
            if (null !== $slot->getSchedulePlanId()) {
                continue;
            }
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

        // P1-4 PR B — les fenêtres d'accès MATCH se recopient comme les créneaux :
        // la convention mairie se renouvelle. Les indisponibilités (VenueUnavailability),
        // elles, sont des faits datés one-shot : JAMAIS recopiées.
        foreach ($this->rows(VenueMatchWindow::class, $clubId, $sourceId) as $window) {
            $newVenueId = $venueMap[$window->getVenueId()] ?? null;
            if (null === $newVenueId) {
                continue; // dangling venue reference — nothing to attach to.
            }
            $copy = new VenueMatchWindow;
            $copy->setClubId($clubId);
            $copy->setSeasonId($target->getId());
            $copy->setVenueId($newVenueId);
            $copy->setDayOfWeek($window->getDayOfWeek());
            $copy->setStartTime($window->getStartTime());
            $copy->setEndTime($window->getEndTime());
            $this->entityManager->persist($copy);
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

        // P1-4 PR C — « nous sommes des êtres d'habitude » : habitudes ET
        // passerelles suivent la saison (remap équipe + gymnase), même statut
        // que les fenêtres d'accès mairie.
        foreach ($this->rows(TeamMatchHabit::class, $clubId, $sourceId) as $habit) {
            $teamId = $teamMap[$habit->getTeamId()] ?? null;
            if (null === $teamId) {
                continue;
            }
            $copy = new TeamMatchHabit;
            $copy->setClubId($clubId);
            $copy->setSeasonId($target->getId());
            $copy->setTeamId($teamId);
            $copy->setDayOfWeek($habit->getDayOfWeek());
            $copy->setKickoffTime($habit->getKickoffTime());
            // A dangling venue reference degrades to a day+time habit — the
            // habit survives, its venue anchor does not.
            $copy->setVenueId(null !== $habit->getVenueId() ? ($venueMap[$habit->getVenueId()] ?? null) : null);
            $this->entityManager->persist($copy);
        }

        foreach ($this->rows(TeamLink::class, $clubId, $sourceId) as $link) {
            $teamAId = $teamMap[$link->getTeamAId()] ?? null;
            $teamBId = $teamMap[$link->getTeamBId()] ?? null;
            if (null === $teamAId || null === $teamBId) {
                continue;
            }
            // The copies keep the normalized order invariant (teamAId < teamBId):
            // the remapped uuids are new, so re-normalize.
            if (strcasecmp($teamAId, $teamBId) > 0) {
                [$teamAId, $teamBId] = [$teamBId, $teamAId];
            }
            $copy = new TeamLink;
            $copy->setClubId($clubId);
            $copy->setSeasonId($target->getId());
            $copy->setTeamAId($teamAId);
            $copy->setTeamBId($teamBId);
            $copy->setLinkType($link->getLinkType());
            $this->entityManager->persist($copy);
        }

        $maps = ['venues' => $venueMap, 'coaches' => $coachMap, 'teams' => $teamMap];
        $constraints = 0;
        // Permanent constraints only: dated ones (calendarEntryId set) belong
        // to season-N periods and die with them.
        foreach ($this->entityManager->getRepository(Constraint::class)->findBy(['clubId' => $clubId, 'seasonId' => $sourceId, 'calendarEntryId' => null]) as $constraint) {
            [$scopeTargetId, $scopeDangling] = $this->remapScopeTarget($constraint, $maps);
            // A dangling scope target (referenced entity already gone in N) →
            // the constraint is broken in N already; do NOT propagate an
            // invalid row (it would fail validation and skip enforcement).
            if ($scopeDangling) {
                continue;
            }
            [$config, $configDangling] = $this->remapConfig($constraint->getConfig(), $maps);
            if ($configDangling) {
                continue;
            }
            $copy = new Constraint;
            $copy->setClubId($clubId);
            $copy->setSeasonId($target->getId());
            $copy->setName($constraint->getName());
            $copy->setDescription($constraint->getDescription());
            $copy->setScope($constraint->getScope());
            $copy->setScopeTargetId($scopeTargetId);
            $copy->setFamily($constraint->getFamily());
            $copy->setRuleType($constraint->getRuleType());
            $copy->setConfig($config);
            $copy->setCreatedBy($constraint->getCreatedBy());
            $copy->setSource($constraint->getSource());
            $copy->setSourceOccurrenceId($constraint->getSourceOccurrenceId());
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

        // Team tags are NOT copied: persisting the copied teams fires
        // TeamTagSyncListener, which re-derives the SYSTEM tags for N+1 on its
        // own. Custom-tag assignments are intentionally left out — the
        // existing TeamTagService wipes every assignment (custom included) and
        // re-creates only system tags on the next team edit, so a copied
        // custom tag would be ephemeral. (Pre-existing limitation, tracked in
        // the roadmap; carrying custom tags across seasons needs that fixed
        // first.)

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

    /**
     * @param array{venues: array<string,string>, coaches: array<string,string>, teams: array<string,string>} $maps
     *
     * @return array{0: ?string, 1: bool} [remapped id | null, dangling?] —
     *                                    dangling = a non-CLUB scope whose target has no copy (broken in N)
     */
    private function remapScopeTarget(Constraint $constraint, array $maps): array
    {
        $targetId = $constraint->getScopeTargetId();
        if (null === $targetId) {
            return [null, false]; // CLUB scope (or targetless) — nothing to remap.
        }

        $mapped = match ($constraint->getScope()) {
            ConstraintScope::TEAM => $maps['teams'][$targetId] ?? null,
            ConstraintScope::COACH => $maps['coaches'][$targetId] ?? null,
            ConstraintScope::FACILITY => $maps['venues'][$targetId] ?? null,
            default => null,
        };

        return [$mapped, null === $mapped];
    }

    /**
     * @param array<string, mixed>                                                                            $config
     * @param array{venues: array<string,string>, coaches: array<string,string>, teams: array<string,string>} $maps
     *
     * @return array{0: array<string, mixed>, 1: bool} [remapped config, dangling?] —
     *                                                 dangling = a config id key points at an entity with no copy
     */
    private function remapConfig(array $config, array $maps): array
    {
        foreach (self::CONFIG_ID_KEYS as $key => $mapName) {
            if (isset($config[$key]) && \is_string($config[$key])) {
                $mapped = $maps[$mapName][$config[$key]] ?? null;
                if (null === $mapped) {
                    // Never carry a season-N id into N+1 — the reference is dead.
                    return [$config, true];
                }
                $config[$key] = $mapped;
            }
        }

        return [$config, false];
    }

    /** "2025" → "2026" · "2025-2026" → "2026-2027" · shifts each 4-digit year by $yearsToAdd; other labels kept. */
    private function nextName(string $name, int $yearsToAdd): string
    {
        $bumped = preg_replace_callback('/\d{4}/', static fn (array $m): string => (string) ((int) $m[0] + $yearsToAdd), $name);

        return $bumped ?? $name;
    }
}
