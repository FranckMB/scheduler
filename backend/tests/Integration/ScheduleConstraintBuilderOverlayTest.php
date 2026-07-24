<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleStatus;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Constraint semantics (NR): a closure overlay expands each closed venue into
 * per-team FACILITY HARD forbiddenVenueId constraints (the shape the engine
 * honors), reuses permanent + dated constraints, and never writes the base
 * schedule-input cache.
 */
#[Group('phase1')]
#[Group('integration')]
final class ScheduleConstraintBuilderOverlayTest extends KernelTestCase
{
    use ProvisionsPeriodPlanTrait;

    use TenantGucTrait;

    private const VENUE_CLOSED = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private EntityManagerInterface $em;

    private ScheduleConstraintBuilder $builder;

    public function testClosureOverlayRemovesTheClosedVenueSlots(): void
    {
        // P2-5 5b : un gymnase fermé sur TOUTE la fenêtre (closure Mon→Sun, config
        // sans dates → fallback tous-jours) perd TOUS ses créneaux du payload — plus
        // de forbiddenVenueId (l'engine ne le voit pas ; il ne peut simplement pas
        // l'utiliser, faute de créneau). Le forbid tous-jours est supprimé.
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $this->team($club, $season, 'U13');
        $this->permanentConstraint($club, $season);
        $entry = $this->closurePeriod($club, $season);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->venue($club, $season, self::VENUE_CLOSED, 'Gym fermé');
        $this->datedClosedVenueConstraint($club, $season, $entry);
        $this->venueSlot($club, $season, self::VENUE_CLOSED, null, 1); // lundi
        $this->venueSlot($club, $season, self::VENUE_CLOSED, null, 4); // jeudi
        $this->em->flush();

        $payload = $this->builder->buildForOverlay($schedule, $entry);

        // Plus AUCUN forbiddenVenueId (mécanisme supprimé).
        $forbidden = array_filter($payload['constraints'], static fn (array $c): bool => isset($c['config']['forbiddenVenueId']));
        self::assertCount(0, $forbidden, 'le forbid tous-jours est remplacé par le retrait de créneaux');
        // Fenêtre entière fermée → le gym n'a plus aucun créneau.
        self::assertSame([], $this->closedVenueWeekdays($schedule, $entry), 'un gym fermé toute la semaine perd tous ses créneaux');

        // Closure additive : la permanente reste.
        $names = array_map(static fn (array $c): string => $c['name'] ?? '', $payload['constraints']);
        self::assertContains('Contrainte permanente', $names);
    }

    public function testWeekClosureRemovesSlotsOnClosedDaysOnly(): void
    {
        // P2-5 5b — LE cœur : fermeture datée jeudi→dimanche sur une semaine pleine
        // lun→dim. Le gym GARDE ses créneaux lun/mar/mer (ouvert), PERD jeu/ven/sam/dim.
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        // Semaine Mon 2026-05-04 → Sun 2026-05-10 ; incident Thu 05-07 → Sun 05-10.
        // Entrée SANS la datée auto de closurePeriod (elle serait sans dates = tous-jours).
        $entry = $this->bareClosurePeriod($club, $season, '2026-05-04', '2026-05-10');
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->venue($club, $season, self::VENUE_CLOSED, 'Gym fermé jeu-dim');
        $this->datedClosedVenueConstraintDated($club, $season, $entry, '2026-05-07', '2026-05-10');
        foreach ([1, 2, 3, 4, 5, 6, 7] as $day) {
            $this->venueSlot($club, $season, self::VENUE_CLOSED, null, $day);
        }
        $this->em->flush();

        $kept = $this->closedVenueWeekdays($schedule, $entry);
        sort($kept);
        self::assertSame([1, 2, 3], $kept, 'le gym reste ouvert lun-mer (hors incident), fermé jeu-dim');
    }

    public function testHolidayOverlayRemovesTheClosedVenueSlots(): void
    {
        // Même mécanisme côté reprise (holiday) : le gym fermé perd ses créneaux.
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $this->permanentConstraint($club, $season);
        $entry = $this->holidayPeriod($club, $season);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->venue($club, $season, self::VENUE_CLOSED, 'Gym fermé');
        $this->datedClosedVenueConstraint($club, $season, $entry);
        $this->venueSlot($club, $season, self::VENUE_CLOSED, null, 2);
        $this->em->flush();

        $names = array_map(static fn (array $c): string => $c['name'] ?? '', $this->builder->buildForOverlay($schedule, $entry)['constraints']);
        self::assertContains('Contrainte permanente', $names, 'a CLUB permanent is inherited in a reprise');
        self::assertSame([], $this->closedVenueWeekdays($schedule, $entry), 'le gym fermé n’a plus de créneau côté reprise');
    }

    public function testOverlayBuildDoesNotWriteBaseCache(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $entry = $this->closurePeriod($club, $season);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        /** @var CacheItemPoolInterface&CacheInterface $pool */
        $pool = self::getContainer()->get('cache.schedule');
        $pool->deleteItem(ScheduleConstraintBuilder::cacheKey($club->getId()));

        $this->builder->buildForOverlay($schedule, $entry);

        self::assertFalse(
            $pool->getItem(ScheduleConstraintBuilder::cacheKey($club->getId()))->isHit(),
            'overlay build must not populate the base schedule-input cache',
        );
    }

    public function testBaseBuildExcludesOverlaySlots(): void
    {
        [$club, $season] = $this->seed();
        // A base schedule with one slot, and an overlay schedule with one slot.
        $base = $this->overlaySchedule($club, $season, null, 'baaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $entry = $this->closurePeriod($club, $season);
        $overlay = $this->overlaySchedule($club, $season, $entry, 'ceeeeeee-eeee-4eee-8eee-eeeeeeeeeeee');
        $baseVenue = '10000000-0000-4000-8000-000000000001';
        $overlayVenue = '20000000-0000-4000-8000-000000000002';
        $this->slot($base, $baseVenue);
        $this->slot($overlay, $overlayVenue);
        $this->em->flush();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        $venues = array_map(static fn (array $s): string => $s['venueId'], $payload['slotTemplates']);
        self::assertContains($baseVenue, $venues, 'base slots feed the base build');
        self::assertNotContains($overlayVenue, $venues, 'overlay slots must NOT leak into the base build');
    }

    public function testOverlayExcludesDeactivatedTeam(): void
    {
        [$club, $season] = $this->seed();
        $teamA = $this->team($club, $season, 'U11');
        $teamB = $this->team($club, $season, 'U13');
        $entry = $this->holidayPeriod($club, $season);
        $this->teamOverride($club, $season, $entry, $teamB, false, null); // B off for the period
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $ids = array_map(static fn (array $t): string => $t['id'], $this->builder->buildForOverlay($schedule, $entry)['teams']);

        self::assertContains($teamA->getId(), $ids, 'an active team is placed');
        self::assertNotContains($teamB->getId(), $ids, 'a team deactivated for the period gets no sessions');
    }

    public function testOverlayHonorsSessionsPerWeekOverride(): void
    {
        [$club, $season] = $this->seed();
        $team = $this->team($club, $season, 'U11'); // seasonal sessionsPerWeek defaults to 2
        $entry = $this->holidayPeriod($club, $season);
        $this->teamOverride($club, $season, $entry, $team, true, 1); // reduced to 1 for the period
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $teams = $this->builder->buildForOverlay($schedule, $entry)['teams'];
        $serialized = array_values(array_filter($teams, static fn (array $t): bool => $t['id'] === $team->getId()))[0];

        self::assertSame(1, $serialized['sessionsPerWeek'], 'the period override replaces the seasonal volume');
    }

    public function testOverlaySlotsAreAdditiveAndBaseExcludesPeriodSlots(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $entry = $this->holidayPeriod($club, $season);
        $seasonalVenue = $this->venue($club, $season, '10000000-0000-4000-8000-000000000011', 'Gym socle');
        $cityVenue = $this->venue($club, $season, '20000000-0000-4000-8000-000000000022', 'Gym mairie');
        $this->venueSlot($club, $season, $seasonalVenue->getId(), null); // seasonal
        $this->venueSlot($club, $season, $cityVenue->getId(), $this->planIdOf($entry)); // prêté à CE plan
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        // Overlay build: seasonal ∪ period (additive).
        $overlayVenues = $this->venuesBySlotCount($this->builder->buildForOverlay($schedule, $entry)['venues']);
        self::assertSame(1, $overlayVenues[$seasonalVenue->getId()], 'seasonal slot kept in the overlay');
        self::assertSame(1, $overlayVenues[$cityVenue->getId()], 'the city-lent period slot is added');

        // Base build: the period slot must NOT leak in.
        $baseVenues = $this->venuesBySlotCount($this->builder->buildForClubSeason($club->getId(), $season->getId())['venues']);
        self::assertSame(1, $baseVenues[$seasonalVenue->getId()], 'seasonal slot feeds the base');
        self::assertSame(0, $baseVenues[$cityVenue->getId()], 'period slot never leaks into the base plan');
    }

    public function testSessionOverrideDoesNotLeakIntoASubsequentBaseBuild(): void
    {
        [$club, $season] = $this->seed();
        $team = $this->team($club, $season, 'U11'); // seasonal sessionsPerWeek = 2
        $entry = $this->holidayPeriod($club, $season);
        $this->teamOverride($club, $season, $entry, $team, true, 1);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        // Overlay build sets the session-override map on the shared builder instance…
        $this->builder->buildForOverlay($schedule, $entry);
        // …a later base build on the SAME instance must NOT inherit it.
        $baseTeams = $this->builder->buildForClubSeason($club->getId(), $season->getId())['teams'];
        $serialized = array_values(array_filter($baseTeams, static fn (array $t): bool => $t['id'] === $team->getId()))[0];

        self::assertSame(2, $serialized['sessionsPerWeek'], 'the base plan keeps the seasonal volume — no override leak');
    }

    /**
     * #8 (fondateur 2026-07-24) — gymnase DÉSACTIVÉ pour la période : il sort entièrement
     * du payload (aucun créneau, saison comme prêté), et rien ne doit plus le nommer —
     * une contrainte FACILITY rescapée serait un id fantôme (solve INFEASIBLE).
     */
    public function testOverlayDisabledVenueLeavesThePayloadEntirely(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $entry = $this->holidayPeriod($club, $season);
        $kept = $this->venue($club, $season, '10000000-0000-4000-8000-0000000000a1', 'Gym gardé');
        $off = $this->venue($club, $season, '10000000-0000-4000-8000-0000000000a2', 'Gym désactivé');
        $this->venueSlot($club, $season, $kept->getId(), null);
        $this->venueSlot($club, $season, $off->getId(), null);                          // créneau de saison
        $this->venueSlot($club, $season, $off->getId(), $this->planIdOf($entry), 2);     // créneau prêté
        // Une contrainte FACILITY qui vise le gymnase désactivé.
        $facility = new Constraint;
        $facility->setClubId($club->getId());
        $facility->setSeasonId($season->getId());
        $facility->setName('Gym désactivé interdit');
        $facility->setScope(ConstraintScope::FACILITY);
        $facility->setFamily(ConstraintFamily::FACILITY);
        $facility->setRuleType(ConstraintRuleType::HARD);
        $facility->setScopeTargetId($off->getId());
        $facility->setCalendarEntryId($entry->getId()); // datée : héritée par l'overlay
        $this->em->persist($facility);
        $this->venueMode($club, $season, $entry, $off->getId(), \App\Enum\VenuePeriodMode::DISABLED);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        // Une réservation rescapée sur le gymnase désactivé (la bascule la supprime côté
        // serveur — ce filtre est la ceinture) : elle ne doit pas atteindre le solveur,
        // elle épinglerait une séance dans un gymnase absent du payload.
        $ghost = new \App\Entity\Reservation;
        $ghost->setClubId($club->getId());
        $ghost->setSeasonId($season->getId());
        $ghost->setSchedulePlanId($this->planIdOf($entry));
        $ghost->setTeamId('30000000-0000-4000-8000-0000000000f1');
        $ghost->setVenueId($off->getId());
        $ghost->setDayOfWeek(1);
        $ghost->setStartTime(new DateTimeImmutable('18:00'));
        $ghost->setDurationMinutes(90);
        $this->em->persist($ghost);
        $this->em->flush();

        $payload = $this->builder->buildForOverlay($schedule, $entry);
        $venueIds = array_map(static fn (array $v): string => $v['id'], $payload['venues']);
        $pinnedVenueIds = array_map(static fn (array $s): ?string => $s['venueId'] ?? null, $payload['slotTemplates']);

        self::assertNotContains($off->getId(), $pinnedVenueIds, 'aucun verrou/réservation ne pointe le gymnase désactivé');

        self::assertContains($kept->getId(), $venueIds, 'un gymnase hérité reste dans le payload');
        self::assertNotContains($off->getId(), $venueIds, 'le gymnase désactivé sort du payload');
        foreach ($payload['constraints'] as $row) {
            if (\is_array($row)) {
                self::assertNotSame($off->getId(), $row['scopeTargetId'] ?? null, 'aucune contrainte ne nomme un gymnase absent du payload');
            }
        }

        // Le planning principal, lui, n'a rien perdu : ses deux gymnases gardent leur créneau.
        $base = $this->venuesBySlotCount($this->builder->buildForClubSeason($club->getId(), $season->getId())['venues']);
        self::assertSame(1, $base[$off->getId()], 'le créneau de saison du gymnase désactivé survit au planning principal');
    }

    /**
     * #8 — gymnase « grille VIERGE » : ses créneaux de SAISON sont ignorés pour la période,
     * ses créneaux PRÊTÉS restent (ils ont été créés pour elle). Le gymnase, lui, demeure
     * dans le payload — ses prêtés le référencent.
     */
    public function testOverlayBlankVenueIgnoresSeasonalSlotsButKeepsBorrowedOnes(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $entry = $this->holidayPeriod($club, $season);
        $blank = $this->venue($club, $season, '10000000-0000-4000-8000-0000000000b1', 'Gym vierge');
        $this->venueSlot($club, $season, $blank->getId(), null, 1);                    // saison → ignoré
        $this->venueSlot($club, $season, $blank->getId(), $this->planIdOf($entry), 3);  // prêté → gardé
        $this->venueMode($club, $season, $entry, $blank->getId(), \App\Enum\VenuePeriodMode::BLANK);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $counts = $this->venuesBySlotCount($this->builder->buildForOverlay($schedule, $entry)['venues']);
        self::assertSame(1, $counts[$blank->getId()], 'seul le créneau prêté reste : la grille repart vierge');

        $base = $this->venuesBySlotCount($this->builder->buildForClubSeason($club->getId(), $season->getId())['venues']);
        self::assertSame(1, $base[$blank->getId()], 'le créneau de saison est intact côté planning principal');
    }

    /**
     * #8 — ÉCARTER un créneau de saison ne retire QUE celui-là, pour CETTE période : ses
     * voisins restent, et le planning principal n'est jamais modifié (décision fondateur).
     */
    public function testOverlayExcludedSeasonalSlotDisappearsAloneAndBasePlanIsUntouched(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $entry = $this->holidayPeriod($club, $season);
        $venue = $this->venue($club, $season, '10000000-0000-4000-8000-0000000000c1', 'Gym partiel');
        $excluded = $this->venueSlot($club, $season, $venue->getId(), null, 1);
        $this->venueSlot($club, $season, $venue->getId(), null, 4); // le voisin, gardé
        $this->em->flush(); // l'exclusion a besoin de l'id du créneau
        $this->excludeSlot($club, $season, $entry, $excluded->getId());
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $counts = $this->venuesBySlotCount($this->builder->buildForOverlay($schedule, $entry)['venues']);
        self::assertSame(1, $counts[$venue->getId()], 'seul le créneau écarté disparaît de la période');

        $base = $this->venuesBySlotCount($this->builder->buildForClubSeason($club->getId(), $season->getId())['venues']);
        self::assertSame(2, $base[$venue->getId()], 'le planning principal garde ses DEUX créneaux — il n’est jamais modifié');
    }

    public function testOverlayDropsConstraintDisabledForPeriod(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $permanent = $this->permanentConstraint($club, $season);
        $entry = $this->closurePeriod($club, $season);
        $this->constraintOverride($club, $season, $entry, $permanent, false); // disabled for this period
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        // Base plan still carries the permanent constraint (sparse diff — base untouched)…
        $baseNames = array_map(static fn (array $c): string => $c['name'] ?? '', $this->builder->buildForClubSeason($club->getId(), $season->getId())['constraints']);
        self::assertContains('Contrainte permanente', $baseNames, 'the base plan keeps the constraint');

        // …but the overlay drops the constraint the manager disabled for the period.
        $overlayNames = array_map(static fn (array $c): string => $c['name'] ?? '', $this->builder->buildForOverlay($schedule, $entry)['constraints']);
        self::assertNotContains('Contrainte permanente', $overlayNames, 'a constraint disabled for the period is not honored in the overlay');
    }

    public function testOverlayKeepsConstraintWithActiveOverride(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $permanent = $this->permanentConstraint($club, $season);
        $entry = $this->closurePeriod($club, $season);
        $this->constraintOverride($club, $season, $entry, $permanent, true); // explicit active = no-op
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $overlayNames = array_map(static fn (array $c): string => $c['name'] ?? '', $this->builder->buildForOverlay($schedule, $entry)['constraints']);
        self::assertContains('Contrainte permanente', $overlayNames, 'only isActive=false drops the constraint');
    }

    public function testRepriseInheritsPermanentsWithSmartDefault(): void
    {
        [$club, $season] = $this->seed();
        $active = $this->team($club, $season, 'SM1'); // reprend (Fanion, actif)
        $paused = $this->team($club, $season, 'U11'); // en pause pour la période
        $entry = $this->holidayPeriod($club, $season);
        $this->teamOverride($club, $season, $entry, $paused, false, null);
        $this->permanentScoped($club, $season, ConstraintScope::CLUB, null, 'Club perm');
        $this->permanentScoped($club, $season, ConstraintScope::TEAM, $active->getId(), 'Team active perm');
        $this->permanentScoped($club, $season, ConstraintScope::TEAM, $paused->getId(), 'Team paused perm');
        $this->permanentScoped($club, $season, ConstraintScope::FACILITY, 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'Facility perm');
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $names = array_map(static fn (array $c): string => $c['name'] ?? '', $this->builder->buildForOverlay($schedule, $entry)['constraints']);
        self::assertContains('Club perm', $names, 'CLUB kept by default in a reprise');
        self::assertContains('Team active perm', $names, 'a TEAM constraint of a team that reprend is kept');
        self::assertNotContains('Team paused perm', $names, 'a TEAM constraint of a paused team is dropped by default');
        self::assertNotContains('Facility perm', $names, 'a FACILITY constraint is dropped by default in a reprise');
    }

    public function testRepriseOverrideDeviatesFromDefault(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'SM1');
        $entry = $this->holidayPeriod($club, $season);
        $clubC = $this->permanentScoped($club, $season, ConstraintScope::CLUB, null, 'Club perm');
        $facilityC = $this->permanentScoped($club, $season, ConstraintScope::FACILITY, 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'Facility perm');
        $this->constraintOverride($club, $season, $entry, $clubC, false); // drop a CLUB kept by default
        $this->constraintOverride($club, $season, $entry, $facilityC, true); // keep a FACILITY dropped by default
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $names = array_map(static fn (array $c): string => $c['name'] ?? '', $this->builder->buildForOverlay($schedule, $entry)['constraints']);
        self::assertNotContains('Club perm', $names, 'an explicit isActive=false drops a CLUB kept by default');
        self::assertContains('Facility perm', $names, 'an explicit isActive=true keeps a FACILITY dropped by default');
    }

    public function testRepriseDropsTeamConstraintForPausedTeamEvenIfKept(): void
    {
        [$club, $season] = $this->seed();
        $paused = $this->team($club, $season, 'U11');
        $entry = $this->holidayPeriod($club, $season);
        $this->teamOverride($club, $season, $entry, $paused, false, null); // en pause
        $c = $this->permanentScoped($club, $season, ConstraintScope::TEAM, $paused->getId(), 'Paused team perm');
        $this->constraintOverride($club, $season, $entry, $c, true); // le gestionnaire la GARDE explicitement
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $names = array_map(static fn (array $c): string => $c['name'] ?? '', $this->builder->buildForOverlay($schedule, $entry)['constraints']);
        self::assertNotContains('Paused team perm', $names, 'a TEAM constraint targeting a paused team never ships (no ghost teamId), even if explicitly kept');
    }

    public function testRepriseDropsTagExpandedRowForPausedTeam(): void
    {
        [$club, $season] = $this->seed();
        $active = $this->team($club, $season, 'SM1');
        $paused = $this->team($club, $season, 'U11');
        $entry = $this->holidayPeriod($club, $season);
        $this->teamOverride($club, $season, $entry, $paused, false, null); // en pause

        // A club-wide constraint targeting a tag BOTH teams carry → expands per-team.
        $tag = (new \App\Entity\TeamTag)->setClubId($club->getId())->setName('loisir')->setIsSystem(false);
        $this->em->persist($tag);
        $this->em->flush();
        foreach ([$active, $paused] as $t) {
            $this->em->persist((new \App\Entity\TeamTagAssignment)->setTagId($tag->getId())->setTeamId($t->getId())->setSeasonId($season->getId()));
        }
        $c = new Constraint;
        $c->setClubId($club->getId());
        $c->setSeasonId($season->getId());
        $c->setName('Tag rule');
        $c->setScope(ConstraintScope::CLUB);
        $c->setFamily(ConstraintFamily::TIME);
        $c->setRuleType(ConstraintRuleType::HARD);
        $c->setConfig(['targetTag' => 'loisir']);
        $this->em->persist($c);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $rows = $this->builder->buildForOverlay($schedule, $entry)['constraints'];
        $targets = array_map(static fn (array $r): string => $r['scopeTargetId'] ?? '', $rows);
        self::assertContains($active->getId(), $targets, 'the tag rule ships for the active team');
        self::assertNotContains($paused->getId(), $targets, 'the tag-expanded row for a paused team is dropped (no ghost teamId)');
    }

    public function testClosureKeepsFacilityPermanentByDefault(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'SM1');
        $entry = $this->closurePeriod($club, $season);
        $this->permanentScoped($club, $season, ConstraintScope::FACILITY, 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'Facility perm');
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        // Fermeture unchanged: the smart default is reprise-only; closure keeps all permanents.
        $names = array_map(static fn (array $c): string => $c['name'] ?? '', $this->builder->buildForOverlay($schedule, $entry)['constraints']);
        self::assertContains('Facility perm', $names, 'a closure keeps all permanent constraints by default (B3+F2 unchanged)');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
    }

    /**
     * @param list<array{id: string, trainingSlots: list<mixed>}> $venues
     *
     * @return array<string, int> venueId → number of training slots in the payload
     */
    private function venuesBySlotCount(array $venues): array
    {
        $counts = [];
        foreach ($venues as $venue) {
            $counts[$venue['id']] = \count($venue['trainingSlots']);
        }

        return $counts;
    }

    private function holidayPeriod(Club $club, Season $season): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setTitle('Reprise');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);

        return $entry;
    }

    private function teamOverride(Club $club, Season $season, CalendarEntry $entry, Team $team, bool $isActive, ?int $sessions): void
    {
        $o = new \App\Entity\TeamPeriodOverride;
        $o->setClubId($club->getId());
        $o->setSeasonId($season->getId());
        $o->setSchedulePlanId($this->planIdOf($entry));
        $o->setTeamId($team->getId());
        $o->setIsActive($isActive);
        $o->setSessionsPerWeek($sessions);
        $this->em->persist($o);
    }

    private function venue(Club $club, Season $season, string $id, string $name): \App\Entity\Venue
    {
        $venue = new \App\Entity\Venue;
        $venue->setId($id);
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setCanSplit(false);
        $venue->setSource('manual');
        $this->em->persist($venue);

        return $venue;
    }

    /** $schedulePlanId : null = créneau saisonnier (base) ; set = prêté à ce plan (lot C3). */
    private function venueSlot(Club $club, Season $season, string $venueId, ?string $schedulePlanId, int $dayOfWeek = 1): \App\Entity\VenueTrainingSlot
    {
        $slot = new \App\Entity\VenueTrainingSlot;
        $slot->setClubId($club->getId());
        $slot->setSeasonId($season->getId());
        $slot->setVenueId($venueId);
        $slot->setDayOfWeek($dayOfWeek);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $slot->setCapacity(1);
        $slot->setSchedulePlanId($schedulePlanId);
        $this->em->persist($slot);

        return $slot;
    }

    /** #8 — mode d'un gymnase POUR la période (sparse : pas de ligne = hériter). */
    private function venueMode(Club $club, Season $season, CalendarEntry $entry, string $venueId, \App\Enum\VenuePeriodMode $mode): void
    {
        $o = new \App\Entity\VenuePeriodOverride;
        $o->setClubId($club->getId());
        $o->setSeasonId($season->getId());
        $o->setSchedulePlanId($this->planIdOf($entry));
        $o->setVenueId($venueId);
        $o->setMode($mode);
        $this->em->persist($o);
    }

    /** #8 — un créneau de SAISON écarté POUR la période (le créneau lui-même survit). */
    private function excludeSlot(Club $club, Season $season, CalendarEntry $entry, string $slotId): void
    {
        $x = new \App\Entity\VenueSlotPeriodExclusion;
        $x->setClubId($club->getId());
        $x->setSeasonId($season->getId());
        $x->setSchedulePlanId($this->planIdOf($entry));
        $x->setVenueTrainingSlotId($slotId);
        $this->em->persist($x);
    }

    /** Créneaux (jours ISO) du gymnase VENUE_CLOSED effectivement présents dans le payload overlay. */
    private function closedVenueWeekdays(Schedule $schedule, CalendarEntry $entry): array
    {
        $payload = $this->builder->buildForOverlay($schedule, $entry);
        foreach ($payload['venues'] as $venue) {
            if (self::VENUE_CLOSED === $venue['id']) {
                return array_map(static fn (array $s): int => $s['dayOfWeek'], $venue['trainingSlots']);
            }
        }

        return [];
    }

    private function datedClosedVenueConstraint(Club $club, Season $season, CalendarEntry $entry): Constraint
    {
        $constraint = new Constraint;
        $constraint->setClubId($club->getId());
        $constraint->setSeasonId($season->getId());
        $constraint->setCalendarEntryId($entry->getId());
        $constraint->setScope(ConstraintScope::FACILITY);
        $constraint->setScopeTargetId(self::VENUE_CLOSED);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setName('Salle fermée');
        $constraint->setConfig(['type' => 'venue_closed']);
        $this->em->persist($constraint);

        return $constraint;
    }

    /** Closure SANS contrainte datée auto — pour piloter la fenêtre datée à la main (5b). */
    private function bareClosurePeriod(Club $club, Season $season, string $start, string $end): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Gym fermé (fenêtre pilotée)');
        $entry->setStartDate(new DateTimeImmutable($start));
        $entry->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);

        return $entry;
    }

    /** venue_closed AVEC dates (l'incident réel) — pour la granularité jour 5b. */
    private function datedClosedVenueConstraintDated(Club $club, Season $season, CalendarEntry $entry, string $start, string $end): void
    {
        $constraint = new Constraint;
        $constraint->setClubId($club->getId());
        $constraint->setSeasonId($season->getId());
        $constraint->setCalendarEntryId($entry->getId());
        $constraint->setScope(ConstraintScope::FACILITY);
        $constraint->setScopeTargetId(self::VENUE_CLOSED);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setName('Salle fermée');
        $constraint->setConfig(['type' => 'venue_closed', 'startDate' => $start, 'endDate' => $end]);
        $this->em->persist($constraint);
    }

    private function slot(Schedule $schedule, string $venueId): void
    {
        $slot = new ScheduleSlotTemplate;
        $slot->setClubId($schedule->getClubId());
        $slot->setSeasonId($schedule->getSeasonId());
        $slot->setScheduleId($schedule->getId());
        $slot->setTeamId('99999999-9999-4999-8999-999999999999');
        $slot->setVenueId($venueId);
        $slot->setDayOfWeek(1);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $this->em->persist($slot);
    }

    private function team(Club $club, Season $season, string $name): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setName($name);
        $team->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc');
        $team->setPriorityTierId(1);
        $this->em->persist($team);

        return $team;
    }

    private function permanentConstraint(Club $club, Season $season): Constraint
    {
        $c = new Constraint;
        $c->setClubId($club->getId());
        $c->setSeasonId($season->getId());
        $c->setName('Contrainte permanente');
        $c->setScope(ConstraintScope::CLUB);
        $c->setFamily(ConstraintFamily::TIME);
        $c->setRuleType(ConstraintRuleType::HARD);
        $this->em->persist($c);

        return $c;
    }

    private function constraintOverride(Club $club, Season $season, CalendarEntry $entry, Constraint $constraint, bool $isActive): void
    {
        $o = new \App\Entity\ConstraintPeriodOverride;
        $o->setClubId($club->getId());
        $o->setSeasonId($season->getId());
        $o->setSchedulePlanId($this->planIdOf($entry));
        $o->setConstraintId($constraint->getId());
        $o->setIsActive($isActive);
        $this->em->persist($o);
    }

    private function permanentScoped(Club $club, Season $season, ConstraintScope $scope, ?string $targetId, string $name): Constraint
    {
        $c = new Constraint;
        $c->setClubId($club->getId());
        $c->setSeasonId($season->getId());
        $c->setName($name);
        $c->setScope($scope);
        $c->setScopeTargetId($targetId);
        $c->setFamily(ConstraintScope::FACILITY === $scope ? ConstraintFamily::FACILITY : ConstraintFamily::TIME);
        $c->setRuleType(ConstraintRuleType::HARD);
        $c->setConfig([]);
        $this->em->persist($c);

        return $c;
    }

    private function closurePeriod(Club $club, Season $season): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Gym fermé');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);

        $dated = new Constraint;
        $dated->setClubId($club->getId());
        $dated->setSeasonId($season->getId());
        $dated->setName('Salle fermée');
        $dated->setScope(ConstraintScope::FACILITY);
        $dated->setScopeTargetId(self::VENUE_CLOSED);
        $dated->setFamily(ConstraintFamily::FACILITY);
        $dated->setRuleType(ConstraintRuleType::HARD);
        $dated->setCalendarEntryId($entry->getId());
        $this->em->persist($dated);

        return $entry;
    }

    private function overlaySchedule(Club $club, Season $season, ?CalendarEntry $entry, ?string $id = null): Schedule
    {
        $schedule = new Schedule;
        if (null !== $id) {
            $schedule->setId($id);
        }
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Overlay');
        $schedule->setStatus(ScheduleStatus::DRAFT);
        // Toute version est liée à son plan en prod (linkSchedule, au POST) : un overlay au
        // plan de sa période, une version de BASE au plan SEASON (le socle). buildForOverlay
        // l'exige (C2) et findBaseSlotTemplates ne prend QUE les versions du plan SEASON (C4).
        $schedule->setSchedulePlanId(null !== $entry ? $this->planIdOf($entry) : $this->seasonPlanIdOf($season));
        $this->em->persist($schedule);

        return $schedule;
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('OVB Club');
        $club->setSlug('ovb-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('OVB' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('ovb-' . $uid . '@test.com');
        $user->setFirstName('O');
        $user->setLastName('B');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $this->em->persist($season);

        $this->em->flush();

        return [$club, $season];
    }
}
