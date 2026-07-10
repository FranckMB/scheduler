<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\CalendarEntryKind;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleStatus;
use App\Service\StructureRestorer;
use App\Service\StructureSnapshotter;
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
        $this->restorer->restore($v1);
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
        $v1 = $this->makeSchedule($club, $season);
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setTitle('Vacances')
            ->setStartDate(new DateTimeImmutable('2026-02-01'))->setEndDate(new DateTimeImmutable('2026-02-15'));
        $this->em->persist($entry);
        // A dated (calendar) constraint — must survive the structure restore.
        $dated = (new Constraint)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Fermeture')->setScope(ConstraintScope::FACILITY)->setScopeTargetId('22222222-2222-4222-8222-222222222222')
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)->setConfig([])
            ->setCalendarEntryId($entry->getId());
        $this->em->persist($dated);
        $v2 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));

        $this->restorer->restore($v1);
        $this->em->clear();

        // Calendar entry, its dated constraint, and BOTH version rows survive.
        self::assertNotNull($this->em->getRepository(CalendarEntry::class)->find($entry->getId()));
        self::assertNotNull($this->em->getRepository(Constraint::class)->find($dated->getId()), 'a dated constraint is calendar, not structure');
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($v1->getId()));
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($v2->getId()));
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
            ->setName('V')->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($schedule);

        return $schedule;
    }
}
