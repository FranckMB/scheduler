<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
use App\Service\ScheduleResultImporter;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class ScheduleResultImporterTest extends TestCase
{
    public function testImportCreatesNewSlots(): void
    {
        $schedule = $this->createSchedule();
        $entityManager = new RecordingEntityManager([], $schedule);

        (new ScheduleResultImporter($entityManager))->import($schedule, [
            'status' => 'completed',
            'slots' => [
                $this->solverSlot('slot-new', 'team-new', 'venue-new', 'coach-new'),
            ],
            'diagnostics' => [],
        ]);

        self::assertCount(1, $entityManager->persisted);
        self::assertSame([], $entityManager->removed);
        self::assertTrue($entityManager->flushed);

        $slot = $entityManager->persisted[0];
        self::assertSame('slot-new', $slot->getId());
        self::assertSame($schedule->getClubId(), $slot->getClubId());
        self::assertSame($schedule->getSeasonId(), $slot->getSeasonId());
        self::assertSame($schedule->getId(), $slot->getScheduleId());
        self::assertSame('team-new', $slot->getTeamId());
        self::assertSame('venue-new', $slot->getVenueId());
        self::assertSame('coach-new', $slot->getCoachId());
        self::assertSame(1, $slot->getDayOfWeek());
        self::assertSame('18:00', $slot->getStartTime()->format('H:i'));
        self::assertSame(90, $slot->getDurationMinutes());
        self::assertSame(LockLevel::NONE, $slot->getLockLevel());
    }

    public function testImportPreservesSoftAndHardSlots(): void
    {
        $schedule = $this->createSchedule();
        $softSlot = $this->createSlot($schedule, 'slot-soft', LockLevel::SOFT, 'team-soft', 'venue-soft', 'coach-soft');
        $hardSlot = $this->createSlot($schedule, 'slot-hard', LockLevel::HARD, 'team-hard', 'venue-hard', 'coach-hard');
        $entityManager = new RecordingEntityManager([$softSlot, $hardSlot], $schedule);

        (new ScheduleResultImporter($entityManager))->import($schedule, [
            'slots' => [
                $this->solverSlot('slot-soft', 'team-changed', 'venue-changed', 'coach-changed', 'NONE'),
                $this->solverSlot('slot-hard', 'team-changed', 'venue-changed', 'coach-changed', 'NONE'),
            ],
        ]);

        self::assertSame([], $entityManager->persisted);
        self::assertSame([], $entityManager->removed);
        self::assertTrue($entityManager->flushed);
        self::assertSame('team-soft', $softSlot->getTeamId());
        self::assertSame('venue-soft', $softSlot->getVenueId());
        self::assertSame('coach-soft', $softSlot->getCoachId());
        self::assertSame(LockLevel::SOFT, $softSlot->getLockLevel());
        self::assertSame('team-hard', $hardSlot->getTeamId());
        self::assertSame('venue-hard', $hardSlot->getVenueId());
        self::assertSame('coach-hard', $hardSlot->getCoachId());
        self::assertSame(LockLevel::HARD, $hardSlot->getLockLevel());
    }

    public function testImportDeletesUnplacedNoneSlots(): void
    {
        $schedule = $this->createSchedule();
        $staleSlot = $this->createSlot($schedule, 'slot-stale', LockLevel::NONE);
        $keptSlot = $this->createSlot($schedule, 'slot-kept', LockLevel::NONE);
        $entityManager = new RecordingEntityManager([$staleSlot, $keptSlot], $schedule);

        (new ScheduleResultImporter($entityManager))->import($schedule, [
            'slots' => [
                $this->solverSlot('slot-kept', 'team-updated', 'venue-updated', null),
            ],
        ]);

        self::assertSame([], $entityManager->persisted);
        self::assertSame([$staleSlot], $entityManager->removed);
        self::assertTrue($entityManager->flushed);
        self::assertSame('team-updated', $keptSlot->getTeamId());
        self::assertSame('venue-updated', $keptSlot->getVenueId());
        self::assertNull($keptSlot->getCoachId());
        self::assertSame('18:00', $keptSlot->getStartTime()->format('H:i'));
    }

    public function testImportHandlesEmptySolverOutput(): void
    {
        $schedule = $this->createSchedule();
        $noneSlot = $this->createSlot($schedule, 'slot-none', LockLevel::NONE);
        $lockedSlot = $this->createSlot($schedule, 'slot-locked', LockLevel::HARD);
        $entityManager = new RecordingEntityManager([$noneSlot, $lockedSlot], $schedule);

        (new ScheduleResultImporter($entityManager))->import($schedule, [
            'status' => 'completed',
            'slots' => [],
        ]);

        self::assertSame([], $entityManager->persisted);
        self::assertSame([$noneSlot], $entityManager->removed);
        self::assertTrue($entityManager->flushed);
        self::assertSame(LockLevel::HARD, $lockedSlot->getLockLevel());
    }

    public function testImportHandlesDuplicateSlotsGracefully(): void
    {
        $schedule = $this->createSchedule();
        $entityManager = new RecordingEntityManager([], $schedule);

        (new ScheduleResultImporter($entityManager))->import($schedule, [
            'slots' => [
                $this->solverSlot('slot-duplicate', 'team-first', 'venue-first', 'coach-first'),
                $this->solverSlot('slot-duplicate', 'team-second', 'venue-second', 'coach-second'),
            ],
        ]);

        self::assertCount(1, $entityManager->persisted);
        self::assertSame([], $entityManager->removed);
        self::assertTrue($entityManager->flushed);
        self::assertSame('slot-duplicate', $entityManager->persisted[0]->getId());
        self::assertSame('team-first', $entityManager->persisted[0]->getTeamId());
    }

    private function createSchedule(): Schedule
    {
        return (new Schedule())
            ->setId('schedule-id')
            ->setClubId('club-id')
            ->setSeasonId('season-id')
            ->setName('Schedule')
            ->setStatus('generating');
    }

    private function createSlot(
        Schedule $schedule,
        string $id,
        LockLevel $lockLevel,
        string $teamId = 'team-id',
        string $venueId = 'venue-id',
        ?string $coachId = 'coach-id',
    ): ScheduleSlotTemplate {
        return (new ScheduleSlotTemplate())
            ->setId($id)
            ->setClubId($schedule->getClubId())
            ->setSeasonId($schedule->getSeasonId())
            ->setScheduleId($schedule->getId())
            ->setTeamId($teamId)
            ->setVenueId($venueId)
            ->setCoachId($coachId)
            ->setDayOfWeek(1)
            ->setStartTime(new \DateTimeImmutable('17:00'))
            ->setDurationMinutes(60)
            ->setLockLevel($lockLevel);
    }

    private function solverSlot(
        string $id,
        string $teamId,
        string $venueId,
        ?string $coachId,
        string $lockLevel = 'NONE',
    ): array {
        return [
            'id' => $id,
            'teamId' => $teamId,
            'venueId' => $venueId,
            'coachId' => $coachId,
            'dayOfWeek' => 1,
            'startTime' => '18:00',
            'durationMinutes' => 90,
            'lockLevel' => $lockLevel,
        ];
    }
}

final class RecordingEntityManager extends EntityManagerDecorator
{
    public array $persisted = [];

    public array $removed = [];

    public bool $flushed = false;

    private RecordingSlotRepository $repository;

    public function __construct(array $existingSlots, Schedule $schedule)
    {
        $this->repository = new RecordingSlotRepository($existingSlots, $schedule);
    }

    public function getRepository(string $className): EntityRepository
    {
        TestCase::assertSame(ScheduleSlotTemplate::class, $className);

        return $this->repository;
    }

    public function persist(object $object): void
    {
        TestCase::assertInstanceOf(ScheduleSlotTemplate::class, $object);
        $this->persisted[] = $object;
    }

    public function remove(object $object): void
    {
        TestCase::assertInstanceOf(ScheduleSlotTemplate::class, $object);
        $this->removed[] = $object;
    }

    public function flush(): void
    {
        $this->flushed = true;
    }
}

final class RecordingSlotRepository extends EntityRepository
{
    public function __construct(private readonly array $existingSlots, private readonly Schedule $schedule)
    {
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        TestCase::assertSame(['scheduleId' => $this->schedule->getId()], $criteria);
        TestCase::assertNull($orderBy);
        TestCase::assertNull($limit);
        TestCase::assertNull($offset);

        return $this->existingSlots;
    }
}
