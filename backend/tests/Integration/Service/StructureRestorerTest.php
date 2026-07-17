<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\CalendarEntryKind;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleStatus;
use App\Service\SchedulePlanProvisioner;
use App\Service\StructureRestorer;
use App\Service\StructureSnapshotter;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * planning-versions D3 (§7.1 planning lifecycle + tenant isolation): restore the
 * club structure to a version's captured photo. Round-trip fidelity, original
 * ids preserved (graph stays consistent), and the calendar / other versions are
 * never touched.
 */
#[Group('phase1')]
#[Group('integration')]
final class StructureRestorerTest extends KernelTestCase
{
    use ProvisionsPeriodPlanTrait;

    use TenantGucTrait;

    private EntityManagerInterface $em;

    private StructureSnapshotter $snapshotter;

    private StructureRestorer $restorer;

    public function testRestoreReplacesCurrentStructureWithThePhotoKeepingIds(): void
    {
        [$club, $season] = $this->seedClubSeason();
        // State A: 1 team + 1 venue + 1 permanent constraint. Capture it on V1.
        $teamA = $this->persistTeam($club, $season, 'SM1');
        $venueA = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Gymnase A')->setSource('manual');
        $constraintA = (new Constraint)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('SM1 mardi')->setScope(ConstraintScope::TEAM)->setScopeTargetId($teamA->getId())
            ->setFamily(ConstraintFamily::DAY)->setRuleType(ConstraintRuleType::HARD)->setConfig(['days' => [2]]);
        $this->em->persist($venueA);
        $this->em->persist($constraintA);
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $teamAId = $teamA->getId();

        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));

        // State B (current): delete SM1, add two other teams — "the structure changed".
        $this->em->remove($teamA);
        $this->em->remove($constraintA);
        $this->em->flush();
        $this->persistTeam($club, $season, 'SM2');
        $this->persistTeam($club, $season, 'SM3');
        $this->em->flush();
        self::assertCount(2, $this->em->getRepository(Team::class)->findBy(['seasonId' => $season->getId()]));

        // Restore V1's conditions.
        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        // Back to state A: exactly SM1 (with its ORIGINAL id), the two added teams gone.
        $teams = $this->em->getRepository(Team::class)->findBy(['seasonId' => $season->getId()]);
        self::assertCount(1, $teams);
        self::assertSame('SM1', $teams[0]->getName());
        self::assertSame($teamAId, $teams[0]->getId(), 'the restored row keeps its original id so the graph stays consistent');
        // The permanent constraint came back and still targets the same team id.
        $constraints = $this->em->getRepository(Constraint::class)->findBy(['seasonId' => $season->getId()]);
        self::assertCount(1, $constraints);
        self::assertSame($teamAId, $constraints[0]->getScopeTargetId());
    }

    public function testRestoreLeavesTheCalendarAndOtherVersionsUntouched(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $this->persistTeam($club, $season, 'SM1');
        // A venue that IS part of the photo — its dated closure must survive.
        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Gym')->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
        $v1 = $this->makeSchedule($club, $season);
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setTitle('Vacances')
            ->setStartDate(new DateTimeImmutable('2026-02-01'))->setEndDate(new DateTimeImmutable('2026-02-15'));
        $this->em->persist($entry);
        // A dated closure on a venue present in the photo — a legit calendar row
        // that must survive the structure restore (only DANGLING refs are purged).
        $dated = (new Constraint)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Fermeture')->setScope(ConstraintScope::FACILITY)->setScopeTargetId($venue->getId())
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)->setConfig([])
            ->setCalendarEntryId($entry->getId());
        $this->em->persist($dated);
        $v2 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));

        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        // Calendar entry, its dated constraint, and BOTH version rows survive.
        self::assertNotNull($this->em->getRepository(CalendarEntry::class)->find($entry->getId()));
        self::assertNotNull($this->em->getRepository(Constraint::class)->find($dated->getId()), 'a dated constraint is calendar, not structure');
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($v1->getId()));
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($v2->getId()));
    }

    public function testRestorePurgesDatedRefsToEntitiesAbsentFromThePhoto(): void
    {
        [$club, $season] = $this->seedClubSeason();
        // Photo A: no team. Capture on V1.
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));

        // Later: a team is created + a dated constraint targets it.
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setTitle('P')
            ->setStartDate(new DateTimeImmutable('2026-02-01'))->setEndDate(new DateTimeImmutable('2026-02-15'));
        $this->em->persist($entry);
        $newTeam = $this->persistTeam($club, $season, 'PostSnap');
        $this->em->flush();
        $ghost = (new Constraint)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Ghost')->setScope(ConstraintScope::TEAM)->setScopeTargetId($newTeam->getId())
            ->setFamily(ConstraintFamily::DAY)->setRuleType(ConstraintRuleType::HARD)->setConfig([])
            ->setCalendarEntryId($entry->getId());
        $this->em->persist($ghost);
        $this->em->flush();
        $ghostId = $ghost->getId();

        // Restore V1 (team gone) → the dated constraint that targeted it is a
        // ghost and gets purged; the calendar entry itself stays.
        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();
        self::assertNull($this->em->getRepository(Constraint::class)->find($ghostId), 'a dated constraint targeting a now-absent team is purged');
        self::assertNotNull($this->em->getRepository(CalendarEntry::class)->find($entry->getId()));
    }

    public function testRestorePurgesConstraintPeriodOverrideWhosePermanentConstraintIsGone(): void
    {
        [$club, $season] = $this->seedClubSeason();
        // Photo A: no permanent constraint. Capture on V1.
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));

        // Later: a permanent constraint, a closure period, and an override disabling it.
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(\App\Enum\CalendarEntryPeriodType::CLOSURE)->setTitle('Fermeture')
            ->setStartDate(new DateTimeImmutable('2026-02-01'))->setEndDate(new DateTimeImmutable('2026-02-15'));
        $this->em->persist($entry);
        $permanent = (new Constraint)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Perm')->setScope(ConstraintScope::CLUB)->setFamily(ConstraintFamily::TIME)->setRuleType(ConstraintRuleType::HARD)->setConfig([]);
        $this->em->persist($permanent);
        $this->em->flush();
        $override = (new ConstraintPeriodOverride)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSchedulePlanId($this->planIdOf($entry))->setConstraintId($permanent->getId())->setIsActive(false);
        $this->em->persist($override);
        $this->em->flush();
        $overrideId = $override->getId();

        // Restore V1 (permanent constraint gone) → the override that referenced it is a
        // ghost and gets purged; the calendar entry itself stays.
        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();
        self::assertNull($this->em->getRepository(ConstraintPeriodOverride::class)->find($overrideId), 'an override whose permanent constraint is now absent is purged');
        self::assertNotNull($this->em->getRepository(CalendarEntry::class)->find($entry->getId()));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->snapshotter = self::getContainer()->get(StructureSnapshotter::class);
        $this->restorer = self::getContainer()->get(StructureRestorer::class);
    }

    /** @return array{0: Club, 1: Season} */
    private function seedClubSeason(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Rest ' . $uid)->setSlug('rest-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());
        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus('active');
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }

    private function persistTeam(Club $club, Season $season, string $name): Team
    {
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSportCategoryId('33333333-3333-3333-3333-333333333333')->setPriorityTierId(1)
            ->setName($name)->setSessionsPerWeek(1)->setIsActive(true);
        $this->em->persist($team);

        return $team;
    }

    private function makeSchedule(Club $club, Season $season): Schedule
    {
        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('V')->setStatus(ScheduleStatus::COMPLETED)
            ->setSchedulePlanId($this->seasonPlanIdOf($season));
        $this->em->persist($schedule);
        // Numéroter : plusieurs versions du MÊME plan SEASON ne peuvent pas rester à
        // version_number 0 (uniq_schedule_plan_version) — linkSchedule les met à V1, V2, …
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($schedule);

        return $schedule;
    }
}
