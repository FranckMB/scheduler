<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\Season;
use App\Enum\ScheduleStatus;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * BCK-01 non-regression: the stuck-schedule watchdog must flip a schedule left
 * in PENDING/GENERATING past the deadline to FAILED (worker crash / lost
 * message / lock-exhaustion), while leaving a fresh in-flight schedule alone.
 */
#[Group('phase1')]
#[Group('integration')]
final class ReconcileStuckSchedulesTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    public function testStuckScheduleIsFailedAndFreshOneIsUntouched(): void
    {
        $kernel = self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Watchdog Club');
        $club->setSlug('watchdog-' . $uid);
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

        $stuck = new Schedule;
        $stuck->setClubId($club->getId());
        $stuck->setSeasonId($season->getId());
        $stuck->setName('stuck');
        $stuck->setStatus(ScheduleStatus::GENERATING);
        $this->linkSeededSchedule($stuck);
        $em->flush();
        $stuckId = $stuck->getId();

        $fresh = new Schedule;
        $fresh->setClubId($club->getId());
        $fresh->setSeasonId($season->getId());
        $fresh->setName('fresh');
        $fresh->setStatus(ScheduleStatus::GENERATING);
        $this->linkSeededSchedule($fresh);
        $em->flush();
        $freshId = $fresh->getId();

        // A stale PENDING schedule: the watchdog is GENERATING-only, so a row
        // that may still be legitimately queued must be left alone (no racing
        // the worker with a premature "failed"). Permanently-failed messages are
        // terminated by ScheduleGenerationFailureListener instead.
        $pending = new Schedule;
        $pending->setClubId($club->getId());
        $pending->setSeasonId($season->getId());
        $pending->setName('pending');
        $pending->setStatus(ScheduleStatus::PENDING);
        $this->linkSeededSchedule($pending);
        $em->flush();
        $pendingId = $pending->getId();

        // The #[ORM\PreUpdate] hook resets updated_at on every ORM flush, so the
        // "old" timestamp is written with raw SQL (RLS allows it under the GUC).
        $em->getConnection()->executeStatement(
            'UPDATE schedule SET updated_at = :old WHERE id IN (:ids)',
            ['old' => new DateTimeImmutable('-2 hours')->format('Y-m-d H:i:sP'), 'ids' => [$stuckId, $pendingId]],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $em->clear();
        $this->clearGuc();

        $command = new Application($kernel)->find('app:schedules:reconcile-stuck');
        $tester = new CommandTester($command);
        $tester->execute(['--older-than' => '30']);
        $tester->assertCommandIsSuccessful();

        $this->scopeGucToClub($club->getId());
        $em->clear();

        $reloadedStuck = $em->getRepository(Schedule::class)->find($stuckId);
        self::assertInstanceOf(Schedule::class, $reloadedStuck);
        self::assertSame(ScheduleStatus::FAILED, $reloadedStuck->getStatus(), 'a stuck schedule must be marked FAILED');

        $reloadedFresh = $em->getRepository(Schedule::class)->find($freshId);
        self::assertInstanceOf(Schedule::class, $reloadedFresh);
        self::assertSame(ScheduleStatus::GENERATING, $reloadedFresh->getStatus(), 'a fresh in-flight schedule must be left untouched');

        $reloadedPending = $em->getRepository(Schedule::class)->find($pendingId);
        self::assertInstanceOf(Schedule::class, $reloadedPending);
        self::assertSame(ScheduleStatus::PENDING, $reloadedPending->getStatus(), 'a stale PENDING schedule must be left untouched (no false-positive)');

        $diagnostics = $em->getRepository(ScheduleDiagnostic::class)->findBy(['scheduleId' => $stuckId, 'type' => 'stuck_timeout']);
        self::assertNotEmpty($diagnostics, 'a stuck_timeout diagnostic must be recorded');
    }
}
