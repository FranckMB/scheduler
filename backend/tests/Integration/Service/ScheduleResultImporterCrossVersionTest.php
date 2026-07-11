<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Service\ScheduleResultImporter;
use App\Service\SlotIdScoper;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * planning-versions (P0-5) non-regression: two schedules sharing a placement no
 * longer collide — importing into schedule B never steals schedule A's row, and
 * re-importing A preserves its own HARD-locked row. Slot ids are per-schedule
 * (uuid5(scheduleId:engineId)), not placement-global.
 */
#[Group('phase1')]
#[Group('integration')]
final class ScheduleResultImporterCrossVersionTest extends KernelTestCase
{
    use TenantGucTrait;

    private const ENGINE_ID = '11111111-1111-4111-8111-111111111111'; // an engine placement-global id
    private const TEAM = '22222222-2222-4222-8222-222222222222';
    private const VENUE = '33333333-3333-4333-8333-333333333333';

    private EntityManagerInterface $em;

    private ScheduleResultImporter $importer;

    public function testScopeIsPerScheduleAndStable(): void
    {
        self::assertSame(SlotIdScoper::scope('A', self::ENGINE_ID), SlotIdScoper::scope('A', self::ENGINE_ID), 'stable for the same (schedule, engineId)');
        self::assertNotSame(SlotIdScoper::scope('A', self::ENGINE_ID), SlotIdScoper::scope('B', self::ENGINE_ID), 'distinct across schedules');
    }

    public function testImportingIntoAnotherVersionDoesNotStealTheSlot(): void
    {
        [$club, $season] = $this->seed();
        $a = $this->schedule($club, $season, ScheduleStatus::VALIDATED);
        $b = $this->schedule($club, $season, ScheduleStatus::PENDING);
        // A owns a HARD-locked slot at placement P (id scoped to A).
        $this->slot($club, $season, $a, LockLevel::HARD);
        $this->em->flush();

        // Generate B with the SAME placement echoed by the engine.
        $this->importer->import($b, ['slots' => [$this->engineSlot(LockLevel::HARD)]]);

        $this->em->clear();
        // A still has its HARD slot, still owned by A.
        $aSlots = $this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $a->getId()]);
        self::assertCount(1, $aSlots, 'A keeps its slot (not stolen)');
        self::assertSame(LockLevel::HARD, $aSlots[0]->getLockLevel());
        self::assertSame(SlotIdScoper::scope($a->getId(), self::ENGINE_ID), $aSlots[0]->getId());
        // B has its OWN distinct row.
        $bSlots = $this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $b->getId()]);
        self::assertCount(1, $bSlots);
        self::assertSame(SlotIdScoper::scope($b->getId(), self::ENGINE_ID), $bSlots[0]->getId());
        self::assertNotSame($aSlots[0]->getId(), $bSlots[0]->getId());
    }

    public function testReimportPreservesTheSchedulesOwnHardLock(): void
    {
        [$club, $season] = $this->seed();
        $a = $this->schedule($club, $season, ScheduleStatus::COMPLETED);
        $this->slot($club, $season, $a, LockLevel::HARD);
        $this->slot($club, $season, $a, LockLevel::NONE, '44444444-4444-4444-8444-444444444444');
        $this->em->flush();

        // Re-generate A: HARD placement echoed again, the old NONE dropped.
        $this->importer->import($a, ['slots' => [$this->engineSlot(LockLevel::HARD)]]);

        $this->em->clear();
        $slots = $this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $a->getId()]);
        // Exactly the HARD slot survives (preserved, not duplicated); the stale NONE is gone.
        self::assertCount(1, $slots);
        self::assertSame(LockLevel::HARD, $slots[0]->getLockLevel());
        self::assertSame(SlotIdScoper::scope($a->getId(), self::ENGINE_ID), $slots[0]->getId());
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->importer = self::getContainer()->get(ScheduleResultImporter::class);
    }

    /** @return array{0: Club, 1: Season} */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('C ' . $uid)->setSlug('c-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')
            ->setOnboardingCompleted(true)->setFfbbClubCode('PSI' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus('active');
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }

    private function schedule(Club $club, Season $season, ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('S')->setStatus($status);
        $this->em->persist($schedule);

        return $schedule;
    }

    private function slot(Club $club, Season $season, Schedule $schedule, LockLevel $lock, string $engineId = self::ENGINE_ID): void
    {
        $slot = (new ScheduleSlotTemplate)
            ->setId(SlotIdScoper::scope($schedule->getId(), $engineId))
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setScheduleId($schedule->getId())
            ->setTeamId(self::TEAM)
            ->setVenueId(self::VENUE)
            ->setDayOfWeek(2)
            ->setStartTime(new DateTimeImmutable('18:00'))
            ->setDurationMinutes(90)
            ->setLockLevel($lock);
        $this->em->persist($slot);
    }

    /** @return array<string, mixed> */
    private function engineSlot(LockLevel $lock): array
    {
        return [
            'id' => self::ENGINE_ID,
            'teamId' => self::TEAM,
            'venueId' => self::VENUE,
            'dayOfWeek' => 2,
            'startTime' => '18:00',
            'durationMinutes' => 90,
            'lockLevel' => $lock->value,
        ];
    }
}
