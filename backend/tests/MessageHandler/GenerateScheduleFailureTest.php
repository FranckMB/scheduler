<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\Season;
use App\Enum\ScheduleStatus;
use App\Message\GenerateScheduleMessage;
use App\MessageHandler\GenerateScheduleHandler;
use App\Service\ClubGenerationLock;
use App\Service\DiagnosticMessageBuilder;
use App\Service\EngineClient;
use App\Service\ScheduleConstraintBuilder;
use App\Service\ScheduleDiagnosticsRecorder;
use App\Service\SchedulePlanProvisioner;
use App\Service\ScheduleProgressPublisher;
use App\Service\ScheduleResultImporter;
use App\Service\SolverMetricsMapper;
use App\Service\SolverMetricsRecorder;
use App\Service\StructureSnapshotter;
use App\Service\TenantConnectionContext;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * BCK-01 non-regression on the generation handler's failure semantics.
 */
#[Group('phase1')]
#[Group('integration')]
final class GenerateScheduleFailureTest extends KernelTestCase
{
    use TenantGucTrait;

    /**
     * Mercure is best-effort: a valid COMPLETED solve must survive a publish
     * failure. Before the fix, a Mercure blip on the post-solve publish threw
     * out of generate() and the ~650 s solve was discarded (marked FAILED /
     * left frozen). The result is persisted before the publish, and the publish
     * is swallowed → the schedule must end COMPLETED.
     */
    public function testMercurePublishFailureDoesNotDiscardACompletedSolve(): void
    {
        [$em, $club, $schedule] = $this->seedScheduleReadyToGenerate('mercure-blip');
        $scheduleId = $schedule->getId();

        $engineResult = json_encode([
            'status' => 'completed',
            'score' => 0,
            'slots' => [],
            'diagnostics' => [],
        ], \JSON_THROW_ON_ERROR);

        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update): string {
            throw new RuntimeException('mercure unavailable');
        });

        $this->runHandler($em, $club->getId(), $scheduleId, new MockHttpClient(new MockResponse($engineResult, ['http_code' => 200])), $hub);

        $this->scopeGucToClub($club->getId());
        $em->clear();
        $reloaded = $em->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $reloaded);
        self::assertSame(
            ScheduleStatus::COMPLETED,
            $reloaded->getStatus(),
            'a persisted COMPLETED solve must survive a best-effort Mercure publish failure',
        );
        self::assertSame(1, $em->getRepository(\App\Entity\SolverMetric::class)->count(['scheduleId' => $scheduleId]));
    }

    /**
     * A genuine error inside generate() (here: the importer rejecting a malformed
     * solver slot) must leave a *clean* terminal FAILED — never a half-success.
     * In particular the season baseline must NOT be designated off a run that
     * failed, and a client-safe diagnostic must be recorded.
     */
    public function testUncaughtGenerationErrorLeavesCleanFailed(): void
    {
        [$em, $club, $schedule, $season] = $this->seedScheduleReadyToGenerate('gen-error');
        $scheduleId = $schedule->getId();
        $seasonId = $season->getId();

        // A HARD slot with an unparseable startTime → ScheduleResultImporter throws.
        $engineResult = json_encode([
            'status' => 'completed',
            'score' => 10,
            'slots' => [[
                'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'teamId' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'venueId' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                'dayOfWeek' => 1,
                'startTime' => 'not-a-time',
                'durationMinutes' => 60,
                'lockLevel' => 'HARD',
            ]],
            'diagnostics' => [],
        ], \JSON_THROW_ON_ERROR);

        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('id');

        $this->runHandler($em, $club->getId(), $scheduleId, new MockHttpClient(new MockResponse($engineResult, ['http_code' => 200])), $hub);

        $this->scopeGucToClub($club->getId());
        $em->clear();

        $reloaded = $em->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $reloaded);
        self::assertSame(ScheduleStatus::FAILED, $reloaded->getStatus(), 'a genuine generation error must leave the schedule FAILED, never frozen');

        $reloadedSeason = $em->getRepository(Season::class)->find($seasonId);
        self::assertInstanceOf(Season::class, $reloadedSeason);
        self::assertNull(
            $em->getConnection()->fetchOne('SELECT chosen_schedule_id FROM schedule_plan WHERE season_id = :sid AND type = \'SEASON\'', ['sid' => $seasonId]) ?: null,
            'a failed run must NOT be pointed at by the season plan',
        );

        $types = array_map(
            static fn (ScheduleDiagnostic $diagnostic): string => $diagnostic->getType(),
            $em->getRepository(ScheduleDiagnostic::class)->findBy(['scheduleId' => $scheduleId]),
        );
        self::assertContains('internal_error', $types, 'a failure diagnostic must be recorded');
        self::assertSame(1, $em->getRepository(\App\Entity\SolverMetric::class)->count(['scheduleId' => $scheduleId]));
    }

    /**
     * @return array{0: EntityManagerInterface, 1: Club, 2: Schedule, 3: Season}
     */
    private function seedScheduleReadyToGenerate(string $slugPrefix): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('BCK01 Club');
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
        $schedule->setName('BCK01 schedule');
        $schedule->setStatus(ScheduleStatus::PENDING);
        $em->persist($schedule);
        $em->flush();
        // Prod links every version at creation (POST → linkSchedule) ; sans plan, le site
        // « socle ? » du handler lèverait dès le build (periodEntryIdOf) et transformerait ce
        // COMPLETED en FAILED. C4 : linkSchedule numérote — la version porte d'abord son plan.
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        $schedule->setSchedulePlanId($provisioner->ensureSeasonPlanId($season->getId()));
        $em->flush();
        $provisioner->linkSchedule($schedule);
        $em->flush();
        $em->clear();

        // Worker context: no GUC when the handler starts.
        $this->clearGuc();

        return [$em, $club, $schedule, $season];
    }

    private function runHandler(
        EntityManagerInterface $em,
        string $clubId,
        string $scheduleId,
        MockHttpClient $httpClient,
        HubInterface $hub,
    ): void {
        $container = self::getContainer();
        $handler = new GenerateScheduleHandler(
            $em,
            $container->get(ScheduleConstraintBuilder::class),
            $container->get(ScheduleResultImporter::class),
            new EngineClient($httpClient),
            new ScheduleProgressPublisher($hub),
            new ScheduleDiagnosticsRecorder($em, $container->get(DiagnosticMessageBuilder::class)),
            new SolverMetricsMapper,
            $container->get(ClubGenerationLock::class),
            $container->get(TenantConnectionContext::class),
            $container->get(StructureSnapshotter::class),
            $container->get(SchedulePlanProvisioner::class),
            null,
            null,
            $container->get(SolverMetricsRecorder::class),
        );

        $handler(new GenerateScheduleMessage(scheduleId: $scheduleId, clubId: $clubId));
    }
}
