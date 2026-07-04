<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\Season;
use App\Enum\ScheduleStatus;
use App\EventListener\ScheduleGenerationFailureListener;
use App\Message\GenerateScheduleMessage;
use App\Service\TenantConnectionContext;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * BCK-01 non-regression: a GenerateScheduleMessage that fails *permanently*
 * (retries exhausted, e.g. lock-exhaustion) must terminate its schedule as
 * FAILED — never leave it a PENDING/GENERATING zombie. A message that will
 * still be retried must be left untouched.
 */
#[Group('phase1')]
#[Group('integration')]
final class ScheduleGenerationFailureListenerTest extends KernelTestCase
{
    use TenantGucTrait;

    public function testPermanentFailureMarksScheduleFailed(): void
    {
        [$em, $clubId, $scheduleId] = $this->seedPendingSchedule('perm-fail');

        $listener = new ScheduleGenerationFailureListener(
            $em,
            self::getContainer()->get(TenantConnectionContext::class),
            $this->recordingHub(),
        );

        $listener($this->failedEvent($scheduleId, $clubId, willRetry: false));

        $this->scopeGucToClub($clubId);
        $em->clear();
        $reloaded = $em->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $reloaded);
        self::assertSame(ScheduleStatus::FAILED, $reloaded->getStatus(), 'a permanently-failed message must fail its schedule');

        $types = array_map(
            static fn (ScheduleDiagnostic $diagnostic): string => $diagnostic->getType(),
            $em->getRepository(ScheduleDiagnostic::class)->findBy(['scheduleId' => $scheduleId]),
        );
        self::assertContains('generation_failed', $types);
    }

    public function testRetryableFailureLeavesScheduleUntouched(): void
    {
        [$em, $clubId, $scheduleId] = $this->seedPendingSchedule('retryable');

        $listener = new ScheduleGenerationFailureListener(
            $em,
            self::getContainer()->get(TenantConnectionContext::class),
            $this->recordingHub(),
        );

        $listener($this->failedEvent($scheduleId, $clubId, willRetry: true));

        $this->scopeGucToClub($clubId);
        $em->clear();
        $reloaded = $em->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $reloaded);
        self::assertSame(ScheduleStatus::PENDING, $reloaded->getStatus(), 'a message that will retry must not be failed yet');
    }

    private function failedEvent(string $scheduleId, string $clubId, bool $willRetry): WorkerMessageFailedEvent
    {
        $event = new WorkerMessageFailedEvent(
            new Envelope(new GenerateScheduleMessage(scheduleId: $scheduleId, clubId: $clubId)),
            'async',
            new RuntimeException('retries exhausted'),
        );
        if ($willRetry) {
            $event->setForRetry();
        }

        return $event;
    }

    private function recordingHub(): HubInterface
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static fn (Update $update): string => 'id');

        return $hub;
    }

    /**
     * @return array{0: EntityManagerInterface, 1: string, 2: string}
     */
    private function seedPendingSchedule(string $slugPrefix): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Listener Club');
        $club->setSlug($slugPrefix . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $em->persist($club);
        $em->flush();

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $em->persist($season);
        $em->flush();

        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('pending');
        $schedule->setStatus(ScheduleStatus::PENDING);
        $em->persist($schedule);
        $em->flush();
        $clubId = $club->getId();
        $scheduleId = $schedule->getId();
        $em->clear();

        // Worker failure context: no GUC set when the listener runs.
        $this->clearGuc();

        return [$em, $clubId, $scheduleId];
    }
}
