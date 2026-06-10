<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ScheduleSlotTemplate;
use App\Entity\TeamConstraint;
use App\Enum\LockLevel;
use App\Service\ManualEditService;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * @group phase1
 */
final class ManualEditServiceTest extends TestCase
{
    public function testApplyPermanentConstraintCreatesTeamConstraint(): void
    {
        $slot = $this->createSlot('slot-1', 'team-1', 'venue-1', 'coach-1');
        $entityManager = new ManualEditRecordingEntityManager([$slot], []);

        $service = new ManualEditService($entityManager);
        $constraint = $service->applyPermanentConstraint($slot, 'forbidden', 'No Friday games', 'user-1');

        self::assertSame('forbidden', $constraint->getType());
        self::assertSame($slot->getClubId(), $constraint->getClubId());
        self::assertSame($slot->getSeasonId(), $constraint->getSeasonId());
        self::assertSame($slot->getTeamId(), $constraint->getTeamId());
        self::assertSame($slot->getDayOfWeek(), $constraint->getDayOfWeek());
        self::assertSame($slot->getStartTime()->format('H:i'), $constraint->getStartTime()?->format('H:i'));
        self::assertSame('19:30', $constraint->getEndTime()?->format('H:i'));
        self::assertSame($slot->getVenueId(), $constraint->getVenueId());
        self::assertSame('No Friday games', $constraint->getReason());
        self::assertSame('user-1', $constraint->getCreatedBy());
        self::assertSame('manual_edit', $constraint->getSource());

        self::assertCount(1, $entityManager->persisted);
        self::assertTrue($entityManager->flushed);
    }

    public function testApplyLockUpdatesLockLevel(): void
    {
        $slot = $this->createSlot('slot-1');
        $entityManager = new ManualEditRecordingEntityManager([$slot], []);

        $service = new ManualEditService($entityManager);
        $service->applyLock($slot, LockLevel::HARD);

        self::assertSame(LockLevel::HARD, $slot->getLockLevel());
        self::assertTrue($entityManager->flushed);
        self::assertSame([], $entityManager->persisted);
    }

    public function testApplyOneTimeUpdateModifiesSlotFields(): void
    {
        $slot = $this->createSlot('slot-1', 'team-1', 'venue-1', 'coach-1');
        $entityManager = new ManualEditRecordingEntityManager([$slot], []);

        $service = new ManualEditService($entityManager);
        $service->applyOneTimeUpdate($slot, [
            'dayOfWeek' => 3,
            'startTime' => new \DateTimeImmutable('14:00'),
            'durationMinutes' => 120,
            'venueId' => 'venue-2',
            'coachId' => 'coach-2',
        ]);

        self::assertSame(3, $slot->getDayOfWeek());
        self::assertSame('14:00', $slot->getStartTime()->format('H:i'));
        self::assertSame(120, $slot->getDurationMinutes());
        self::assertSame('venue-2', $slot->getVenueId());
        self::assertSame('coach-2', $slot->getCoachId());
        self::assertTrue($entityManager->flushed);
    }

    public function testApplyOneTimeUpdateThrowsOnCoachDoubleBook(): void
    {
        $slot = $this->createSlot('slot-1', 'team-1', 'venue-1', 'coach-1', 1, '18:00', 90);
        $conflictingSlot = $this->createSlot('slot-2', 'team-2', 'venue-2', 'coach-1', 1, '18:30', 60);
        $entityManager = new ManualEditRecordingEntityManager([$slot, $conflictingSlot], []);

        $service = new ManualEditService($entityManager);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflict detected with slot(s): slot-2.');

        $service->applyOneTimeUpdate($slot, [
            'coachId' => 'coach-1',
        ]);
    }

    public function testApplyOneTimeUpdateThrowsOnVenueDoubleBook(): void
    {
        $slot = $this->createSlot('slot-1', 'team-1', 'venue-1', 'coach-1', 1, '18:00', 90);
        $conflictingSlot = $this->createSlot('slot-2', 'team-2', 'venue-1', 'coach-2', 1, '18:30', 60);
        $entityManager = new ManualEditRecordingEntityManager([$slot, $conflictingSlot], []);

        $service = new ManualEditService($entityManager);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflict detected with slot(s): slot-2.');

        $service->applyOneTimeUpdate($slot, [
            'venueId' => 'venue-1',
        ]);
    }

    public function testApplyOneTimeUpdateAllowsNoConflictUpdate(): void
    {
        $slot = $this->createSlot('slot-1', 'team-1', 'venue-1', 'coach-1', 1, '18:00', 90);
        $otherSlot = $this->createSlot('slot-2', 'team-2', 'venue-2', 'coach-2', 1, '20:00', 60);
        $entityManager = new ManualEditRecordingEntityManager([$slot, $otherSlot], []);

        $service = new ManualEditService($entityManager);
        $service->applyOneTimeUpdate($slot, [
            'coachId' => 'coach-2',
            'venueId' => 'venue-2',
        ]);

        self::assertSame('coach-2', $slot->getCoachId());
        self::assertSame('venue-2', $slot->getVenueId());
        self::assertTrue($entityManager->flushed);
    }

    public function testApplyOneTimeUpdateAllowsAdjacentSlots(): void
    {
        $slot = $this->createSlot('slot-1', 'team-1', 'venue-1', 'coach-1', 1, '18:00', 90);
        $adjacentSlot = $this->createSlot('slot-2', 'team-2', 'venue-1', 'coach-1', 1, '19:30', 60);
        $entityManager = new ManualEditRecordingEntityManager([$slot, $adjacentSlot], []);

        $service = new ManualEditService($entityManager);
        $service->applyOneTimeUpdate($slot, [
            'durationMinutes' => 90,
        ]);

        self::assertSame(90, $slot->getDurationMinutes());
        self::assertTrue($entityManager->flushed);
    }

    private function createSlot(
        string $id,
        string $teamId = 'team-id',
        string $venueId = 'venue-id',
        ?string $coachId = 'coach-id',
        int $dayOfWeek = 1,
        string $startTime = '18:00',
        int $durationMinutes = 90,
    ): ScheduleSlotTemplate {
        return (new ScheduleSlotTemplate())
            ->setId($id)
            ->setClubId('club-id')
            ->setSeasonId('season-id')
            ->setScheduleId('schedule-id')
            ->setTeamId($teamId)
            ->setVenueId($venueId)
            ->setCoachId($coachId)
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(new \DateTimeImmutable($startTime))
            ->setDurationMinutes($durationMinutes)
            ->setLockLevel(LockLevel::NONE);
    }
}

final class ManualEditRecordingEntityManager extends EntityManagerDecorator
{
    public array $persisted = [];

    public bool $flushed = false;

    private ManualEditRecordingSlotRepository $slotRepository;

    private ManualEditRecordingConstraintRepository $constraintRepository;

    /**
     * @param ScheduleSlotTemplate[] $existingSlots
     * @param TeamConstraint[]       $existingConstraints
     */
    public function __construct(array $existingSlots, array $existingConstraints)
    {
        $this->slotRepository = new ManualEditRecordingSlotRepository($existingSlots);
        $this->constraintRepository = new ManualEditRecordingConstraintRepository($existingConstraints);
    }

    public function getRepository(string $className): EntityRepository
    {
        return match ($className) {
            ScheduleSlotTemplate::class => $this->slotRepository,
            TeamConstraint::class => $this->constraintRepository,
            default => throw new \InvalidArgumentException(sprintf('Unexpected repository class: %s', $className)),
        };
    }

    public function persist(object $object): void
    {
        $this->persisted[] = $object;
    }

    public function flush(): void
    {
        $this->flushed = true;
    }
}

final class ManualEditRecordingSlotRepository extends EntityRepository
{
    /**
     * @param ScheduleSlotTemplate[] $existingSlots
     */
    public function __construct(private readonly array $existingSlots)
    {
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        if (isset($criteria['scheduleId'])) {
            return array_filter(
                $this->existingSlots,
                static fn (ScheduleSlotTemplate $slot): bool => $slot->getScheduleId() === $criteria['scheduleId']
            );
        }

        return [];
    }

    public function find(mixed $id, \Doctrine\DBAL\LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        foreach ($this->existingSlots as $slot) {
            if ($slot->getId() === $id) {
                return $slot;
            }
        }

        return null;
    }
}

final class ManualEditRecordingConstraintRepository extends EntityRepository
{
    /**
     * @param TeamConstraint[] $existingConstraints
     */
    public function __construct(private readonly array $existingConstraints)
    {
    }
}
