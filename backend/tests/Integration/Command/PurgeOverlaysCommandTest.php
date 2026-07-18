<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\PurgeOverlaysCommand;
use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ScheduleStatus;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * planning-versions (overlay versions) NR: app:overlays:purge deletes every
 * overlay version of a period whose endDate has passed, and ONLY those — a
 * still-upcoming period's overlay and the season plans survive. Dry-run touches
 * nothing.
 */
#[Group('phase1')]
#[Group('integration')]
final class PurgeOverlaysCommandTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testPurgesOnlyEndedPeriodOverlays(): void
    {
        $f = $this->seed();

        $tester = $this->runPurge(['--club' => $f['clubId'], '--date' => '2026-06-01']);
        $tester->assertCommandIsSuccessful();
        $this->em->clear();
        $this->scopeGucToClub($f['clubId']);

        // The ended period's two overlay versions are gone; even the one the plan pointed at
        // (lot D-b : la version active est dérivée, la purge emporte TOUTES les versions).
        self::assertNull($this->em->getRepository(Schedule::class)->find($f['endedV1']));
        self::assertNull($this->em->getRepository(Schedule::class)->find($f['endedV2']));
        self::assertNull(self::getContainer()->get(SchedulePlanProvisioner::class)->chosenOfPeriodPlan($f['endedEntry']), 'le plan de la période échue ne pointe plus aucune version');
        // The upcoming period's overlay and the season plan survive.
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($f['upcomingOverlay']));
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($f['seasonPlan']));
    }

    public function testDryRunDeletesNothing(): void
    {
        $f = $this->seed();

        $tester = $this->runPurge(['--club' => $f['clubId'], '--date' => '2026-06-01', '--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        $this->em->clear();
        $this->scopeGucToClub($f['clubId']);

        self::assertNotNull($this->em->getRepository(Schedule::class)->find($f['endedV1']));
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($f['endedV2']));
        self::assertStringContainsString('would', $tester->getDisplay());
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runPurge(array $options): CommandTester
    {
        $tester = new CommandTester(self::getContainer()->get(PurgeOverlaysCommand::class));
        $tester->execute($options);

        return $tester;
    }

    /**
     * A club with an ENDED period (2 overlay versions) + an UPCOMING period
     * (1 overlay) + a season plan. "Today" for the run is 2026-06-01.
     *
     * @return array<string, string>
     */
    private function seed(): array
    {
        $uid = uniqid('', true);

        $club = (new Club)->setName('Club purge overlay')->setSlug('cpo-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true)
            ->setFfbbClubCode('CPO' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus('active');
        $this->em->persist($season);
        $this->em->flush();

        $endedEntry = $this->period($club, $season, 'Ended', '2026-05-04', '2026-05-10');
        $upcomingEntry = $this->period($club, $season, 'Upcoming', '2026-09-01', '2026-09-10');
        $this->em->flush();

        // Une période échue peut porter plusieurs versions : la purge les emporte
        // TOUTES, y compris celle que le plan de la période pointe (la période est
        // passée — inv. 10 : le plan meurt avec son entrée).
        $endedV1 = $this->overlay($club, $season, $endedEntry, ScheduleStatus::COMPLETED);
        $endedV2 = $this->overlay($club, $season, $endedEntry, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($endedV2); // V2 validée = pointée par le plan de la période
        $upcomingOverlay = $this->overlay($club, $season, $upcomingEntry, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($upcomingOverlay);
        $seasonPlan = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Plan')->setStatus(ScheduleStatus::COMPLETED);
        // lot D : un plan SEASON aussi porte schedule_plan_id (NOT NULL) — linkSeededSchedule(null)
        // résout le plan SEASON de la saison, le pose AVANT de persister, numérote et flush.
        $this->linkSeededSchedule($seasonPlan);
        $this->em->flush();

        return [
            'clubId' => $club->getId(),
            'endedEntry' => $endedEntry->getId(),
            'endedV1' => $endedV1->getId(),
            'endedV2' => $endedV2->getId(),
            'upcomingOverlay' => $upcomingOverlay->getId(),
            'seasonPlan' => $seasonPlan->getId(),
        ];
    }

    private function period(Club $club, Season $season, string $title, string $start, string $end): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle($title);
        $entry->setStartDate(new DateTimeImmutable($start));
        $entry->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);

        return $entry;
    }

    private function overlay(Club $club, Season $season, CalendarEntry $entry, ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setName('Overlay')
            ->setStatus($status);
        // Prod links every overlay version to its period plan (born du geste) ; sans ça,
        // depuis C4 la purge (qui trouve les versions PAR le plan) ne les verrait pas.
        // lot D : linkSeededSchedule pose le plan AVANT de persister (schedule_plan_id NOT NULL),
        // puis persiste, numérote et flush — donc pas de persist/flush anticipé ici.
        $this->linkSeededSchedule($schedule, $entry->getId());

        return $schedule;
    }
}
