<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleStatus;
use App\Enum\TeamCoachRole;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SeasonAlreadyTransitionedException;
use App\Service\SeasonResolver;
use App\Service\SeasonTransitionService;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Season transition copy NR (spec transition-de-saison §2-3): the ENTRIES of
 * N are copied into N+1 with every cross-reference remapped, permanent
 * constraints only, lineage in parent_*_id, and NOTHING generated copied.
 */
#[Group('phase1')]
#[Group('integration')]
final class SeasonTransitionServiceTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private SeasonTransitionService $service;

    public function testCopiesTheFullEntryGraphWithRemappedReferences(): void
    {
        [$club, $season, $refs] = $this->createClubGraph();

        $target = $this->service->transition($season);

        // Season shell: dates +1 year, draft, gates null, lineage in transitionData.
        self::assertSame($club->getId(), $target->getClubId());
        self::assertSame($season->getStartDate()->modify('+1 year')->format('Y-m-d'), $target->getStartDate()->format('Y-m-d'));
        self::assertSame('draft', $target->getStatus());
        self::assertNull($this->chosenPlanVersion($target), 'N+1 starts as an empty espace de travail');
        self::assertSame($season->getId(), $target->getTransitionData()['sourceSeasonId']);
        self::assertSame($target->getId(), $season->getTransitionData()['transitionedTo']);

        $counts = $target->getTransitionData()['counts'];
        self::assertSame(
            ['venues' => 2, 'venueTrainingSlots' => 1, 'coaches' => 2, 'teams' => 2, 'teamCoaches' => 1, 'coachPlayerMemberships' => 1, 'constraints' => 2],
            $counts,
        );

        // Venue lineage + slot remap.
        $newVenues = $this->em->getRepository(Venue::class)->findBy(['seasonId' => $target->getId()], ['name' => 'ASC']);
        self::assertSame([$refs['venueA']->getId(), $refs['venueB']->getId()], [$newVenues[0]->getParentVenueId(), $newVenues[1]->getParentVenueId()]);
        $newSlot = $this->em->getRepository(VenueTrainingSlot::class)->findOneBy(['seasonId' => $target->getId()]);
        self::assertSame($newVenues[0]->getId(), $newSlot?->getVenueId());

        // Team: forcedVenueId remapped to the copied venue, name untouched.
        $newTeams = $this->em->getRepository(Team::class)->findBy(['seasonId' => $target->getId()], ['name' => 'ASC']);
        self::assertSame('SM1', $newTeams[0]->getName());
        self::assertSame($newVenues[0]->getId(), $newTeams[0]->getForcedVenueId());
        self::assertSame($refs['teamA']->getId(), $newTeams[0]->getParentTeamId());
        // Club-scoped referentials shared: same category/tier ids.
        self::assertSame($refs['teamA']->getSportCategoryId(), $newTeams[0]->getSportCategoryId());

        // Links remapped on BOTH ends.
        $newLink = $this->em->getRepository(TeamCoach::class)->findOneBy(['seasonId' => $target->getId()]);
        $newCoaches = $this->em->getRepository(Coach::class)->findBy(['seasonId' => $target->getId()], ['firstName' => 'ASC']);
        self::assertSame($newTeams[0]->getId(), $newLink?->getTeamId());
        self::assertSame($newCoaches[0]->getId(), $newLink?->getCoachId());
        $newMembership = $this->em->getRepository(CoachPlayerMembership::class)->findOneBy(['seasonId' => $target->getId()]);
        self::assertSame($newTeams[1]->getId(), $newMembership?->getTeamId());

        // Team tags are NOT copied by the transition — TeamTagSyncListener
        // re-derives the SYSTEM tags for the copied teams on its own, and
        // custom tags are intentionally left out (ephemeral pre-existing
        // behaviour). So the custom tag from N must NOT appear in N+1.
        $customInTarget = $this->em->getRepository(TeamTagAssignment::class)->findOneBy(['seasonId' => $target->getId(), 'tagId' => $refs['tag']->getId()]);
        self::assertNull($customInTarget);
    }

    public function testConstraintsArePermanentOnlyWithRemappedTargets(): void
    {
        [, $season, $refs] = $this->createClubGraph();

        $target = $this->service->transition($season);

        $copied = $this->em->getRepository(Constraint::class)->findBy(['seasonId' => $target->getId()], ['name' => 'ASC']);
        // The dated constraint (calendarEntryId set) is NOT copied.
        self::assertCount(2, $copied);
        self::assertSame(['Coach indispo', 'Salle interdite'], [$copied[0]->getName(), $copied[1]->getName()]);

        $newCoach = $this->em->getRepository(Coach::class)->findOneBy(['seasonId' => $target->getId(), 'firstName' => 'Anna']);
        $newVenueB = $this->em->getRepository(Venue::class)->findOneBy(['seasonId' => $target->getId(), 'name' => 'Gym B']);

        // scopeTargetId remapped per scope; config id keys remapped too.
        self::assertSame($newCoach?->getId(), $copied[0]->getScopeTargetId());
        self::assertSame($newCoach?->getId(), $copied[0]->getConfig()['coachId']);
        self::assertSame($newVenueB?->getId(), $copied[1]->getConfig()['forbiddenVenueId']);
        // Lineage.
        self::assertSame($refs['coachConstraint']->getId(), $copied[0]->getParentConstraintId());
    }

    public function testDanglingConstraintReferencesAreSkippedNotPropagated(): void
    {
        [$club, $season] = $this->createClubGraph();
        // A constraint whose scope target no longer exists in N (deleted entity)
        // and one whose config id is dangling — both must NOT be copied.
        $ghost = new Constraint;
        $ghost->setClubId($club->getId());
        $ghost->setSeasonId($season->getId());
        $ghost->setName('Cible fantôme');
        $ghost->setScope(ConstraintScope::TEAM);
        $ghost->setScopeTargetId('deadbeef-0000-4000-8000-000000000000');
        $ghost->setFamily(ConstraintFamily::TIME);
        $ghost->setRuleType(ConstraintRuleType::HARD);
        $ghost->setConfig(['maxStartTime' => '19:30']);
        $this->em->persist($ghost);

        $ghostConfig = new Constraint;
        $ghostConfig->setClubId($club->getId());
        $ghostConfig->setSeasonId($season->getId());
        $ghostConfig->setName('Config fantôme');
        $ghostConfig->setScope(ConstraintScope::CLUB);
        $ghostConfig->setFamily(ConstraintFamily::FACILITY);
        $ghostConfig->setRuleType(ConstraintRuleType::PREFERRED);
        $ghostConfig->setConfig(['forbiddenVenueId' => 'deadbeef-1111-4000-8000-000000000000']);
        $this->em->persist($ghostConfig);
        $this->em->flush();

        $target = $this->service->transition($season);

        $copiedNames = array_map(
            static fn (Constraint $c): string => $c->getName(),
            $this->em->getRepository(Constraint::class)->findBy(['seasonId' => $target->getId()]),
        );
        self::assertNotContains('Cible fantôme', $copiedNames);
        self::assertNotContains('Config fantôme', $copiedNames);
        // The valid permanent constraints are still copied.
        self::assertContains('Coach indispo', $copiedNames);
    }

    public function testNothingGeneratedIsCopied(): void
    {
        [, $season] = $this->createClubGraph();

        $target = $this->service->transition($season);

        self::assertCount(0, $this->em->getRepository(Schedule::class)->findBy(['seasonId' => $target->getId()]));
    }

    public function testCanPrepareNextSeasonInJuneFromASettledCurrentSeason(): void
    {
        // Real anticipation flow (spec §1): mid-June, the current season's plan
        // points at a version; the manager prepares next season ahead of the rush.
        // The transition works and N+1 starts as an empty espace de travail, so
        // the cockpit gate makes it build its own plan.
        $club = $this->minimalClub();
        // Season 2025-26 (started Aug 2025) — current on 2026-06-01, and settled.
        $current = $this->createSeason($club, 2025);
        $this->settleSeasonPlan($current);

        $june1 = new DateTimeImmutable('2026-06-01');
        $target = $this->service->transition($current, $june1);

        self::assertSame('draft', $target->getStatus());
        self::assertNull($this->chosenPlanVersion($target), 'N+1 must not inherit the pointer');
        // N+1 = the 2026-27 season-year.
        self::assertSame('2026-08-01', $target->getStartDate()->format('Y-m-d'));
        self::assertSame($current->getId(), $target->getTransitionData()['sourceSeasonId']);
    }

    public function testEnginePayloadOfTransitionedSeasonReferencesCopiedEntities(): void
    {
        // Constraint-semantics NR (§7.1): a constraint copied by the transition
        // must be HONOURED when generating N+1 — i.e. the engine payload built
        // for the target season references the COPIED entities, never season-N ids.
        [$club, $season, $refs] = $this->createClubGraph();

        $target = $this->service->transition($season);

        $builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
        $payload = $builder->buildForClubSeason($club->getId(), $target->getId());

        $newCoach = $this->em->getRepository(Coach::class)->findOneBy(['seasonId' => $target->getId(), 'firstName' => 'Anna']);
        $newTeams = $this->em->getRepository(Team::class)->findBy(['seasonId' => $target->getId()], ['name' => 'ASC']);

        $payloadTeamIds = array_column($payload['teams'], 'id');
        self::assertContains($newTeams[0]->getId(), $payloadTeamIds);
        self::assertNotContains($refs['teamA']->getId(), $payloadTeamIds);

        $coachConstraints = array_values(array_filter(
            $payload['constraints'],
            static fn (array $c): bool => 'Coach indispo' === ($c['name'] ?? null),
        ));
        self::assertNotEmpty($coachConstraints);
        self::assertSame($newCoach?->getId(), $coachConstraints[0]['scopeTargetId']);
        self::assertSame($newCoach?->getId(), $coachConstraints[0]['config']['coachId']);
    }

    public function testRerunReturnsConflictWithTheExistingSuccessor(): void
    {
        [, $season] = $this->createClubGraph();
        $target = $this->service->transition($season);

        try {
            $this->service->transition($season);
            self::fail('Expected SeasonAlreadyTransitionedException');
        } catch (SeasonAlreadyTransitionedException $e) {
            self::assertSame($target->getId(), $e->getExistingSeasonId());
        }
    }

    public function testNonCurrentSourceIsRefused(): void
    {
        [$club, $season] = $this->createClubGraph();
        // Add a PAST season and try to transition it: refused.
        $past = $this->createSeason($club, SeasonResolver::seasonYear($season->getStartDate()) - 1);
        $this->em->flush();

        $this->expectException(ConflictHttpException::class);
        $this->service->transition($past);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(SeasonTransitionService::class);
    }

    /**
     * One club, one current season, a full entry graph:
     * 2 venues (A with slot, B), 2 coaches (Anna, Bob), 2 teams (SM1 forced on
     * venue A + tagged, U13 with Bob as player), 1 team-coach link, 1
     * membership, 3 constraints (COACH scoped + config.coachId, FACILITY
     * config.forbiddenVenueId, 1 DATED excluded) and 1 generated Schedule.
     *
     * @return array{0: Club, 1: Season, 2: array<string, object>}
     */
    private function createClubGraph(): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club transition');
        $club->setSlug('club-transition-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('TRA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $sport = new Sport;
        $sport->setName('Basketball ' . $uid);
        $sport->setSlug('basket-' . $uid);
        $this->em->persist($sport);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('Séniors ' . substr($uid, -4));
        $category->setIsCustom(false);
        $category->setSortOrder(0);
        $this->em->persist($category);

        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = new PriorityTier;
            $tier->setId(1);
            $tier->setLabel('S');
            $tier->setName('Senior');
            $tier->setColor('#FF0000');
            $tier->setOrToolsWeight(100);
            $tier->setDefaultMinSessions(2);
            $this->em->persist($tier);
        }

        $season = $this->createSeason($club, SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $this->em->flush();

        $venueA = $this->venue($club, $season, 'Gym A');
        $venueB = $this->venue($club, $season, 'Gym B');

        $slot = new VenueTrainingSlot;
        $slot->setClubId($club->getId());
        $slot->setSeasonId($season->getId());
        $slot->setVenueId($venueA->getId());
        $slot->setDayOfWeek(2);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $slot->setCapacity(1);
        $this->em->persist($slot);

        $anna = $this->coach($club, $season, 'Anna');
        $bob = $this->coach($club, $season, 'Bob');

        $teamA = $this->team($club, $season, 'SM1', $category->getId(), (int) $tier->getId(), $venueA->getId());
        $teamB = $this->team($club, $season, 'U13', $category->getId(), (int) $tier->getId(), null);

        $link = new TeamCoach;
        $link->setClubId($club->getId());
        $link->setSeasonId($season->getId());
        $link->setTeamId($teamA->getId());
        $link->setCoachId($anna->getId());
        $link->setRole(TeamCoachRole::MAIN);
        $link->setIsRequired(true);
        $this->em->persist($link);

        $membership = new CoachPlayerMembership;
        $membership->setClubId($club->getId());
        $membership->setSeasonId($season->getId());
        $membership->setCoachId($bob->getId());
        $membership->setTeamId($teamB->getId());
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $tag = new TeamTag;
        $tag->setClubId($club->getId());
        $tag->setName('JEUNE-' . substr($uid, -4));
        $tag->setIsSystem(false);
        $this->em->persist($tag);
        $this->em->flush();

        $assignment = new TeamTagAssignment;
        $assignment->setSeasonId($season->getId());
        $assignment->setTeamId($teamA->getId());
        $assignment->setTagId($tag->getId());
        $this->em->persist($assignment);

        $coachConstraint = new Constraint;
        $coachConstraint->setClubId($club->getId());
        $coachConstraint->setSeasonId($season->getId());
        $coachConstraint->setName('Coach indispo');
        $coachConstraint->setScope(ConstraintScope::COACH);
        $coachConstraint->setScopeTargetId($anna->getId());
        $coachConstraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $coachConstraint->setRuleType(ConstraintRuleType::HARD);
        $coachConstraint->setConfig(['coachId' => $anna->getId(), 'unavailableDays' => [1]]);
        $this->em->persist($coachConstraint);

        $venueConstraint = new Constraint;
        $venueConstraint->setClubId($club->getId());
        $venueConstraint->setSeasonId($season->getId());
        $venueConstraint->setName('Salle interdite');
        $venueConstraint->setScope(ConstraintScope::TEAM);
        $venueConstraint->setScopeTargetId($teamA->getId());
        $venueConstraint->setFamily(ConstraintFamily::FACILITY);
        $venueConstraint->setRuleType(ConstraintRuleType::PREFERRED);
        $venueConstraint->setConfig(['forbiddenVenueId' => $venueB->getId()]);
        $this->em->persist($venueConstraint);

        $dated = new Constraint;
        $dated->setClubId($club->getId());
        $dated->setSeasonId($season->getId());
        $dated->setName('Fermeture datée');
        $dated->setScope(ConstraintScope::FACILITY);
        $dated->setScopeTargetId($venueA->getId());
        $dated->setFamily(ConstraintFamily::FACILITY);
        $dated->setRuleType(ConstraintRuleType::HARD);
        $dated->setConfig(['type' => 'venue_closed', 'startDate' => '2026-05-04', 'endDate' => '2026-05-10']);
        $dated->setCalendarEntryId('99999999-9999-4999-8999-999999999999');
        $this->em->persist($dated);

        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Plan N');
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($schedule);

        $this->em->flush();

        return [$club, $season, [
            'venueA' => $venueA,
            'venueB' => $venueB,
            'teamA' => $teamA,
            'coachConstraint' => $coachConstraint,
            'tag' => $tag,
        ]];
    }

    private function minimalClub(): Club
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club juin');
        $club->setSlug('club-juin-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('JUN' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        return $club;
    }

    private function createSeason(Club $club, int $startYear): Season
    {
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $startYear);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->em->persist($season);

        return $season;
    }

    private function venue(Club $club, Season $season, string $name): Venue
    {
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function coach(Club $club, Season $season, string $firstName): Coach
    {
        $coach = new Coach;
        $coach->setClubId($club->getId());
        $coach->setSeasonId($season->getId());
        $coach->setFirstName($firstName);
        $coach->setLastName('Test');
        $this->em->persist($coach);
        $this->em->flush();

        return $coach;
    }

    private function team(Club $club, Season $season, string $name, string $categoryId, int $tierId, ?string $forcedVenueId): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($categoryId);
        $team->setPriorityTierId($tierId);
        $team->setName($name);
        $team->setSessionsPerWeek(2);
        $team->setForcedVenueId($forcedVenueId);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }
}
