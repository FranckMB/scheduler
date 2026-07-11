<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleStatus;
use App\Message\GenerateScheduleMessage;
use App\MessageHandler\GenerateScheduleHandler;
use App\Service\ClubGenerationLock;
use App\Service\DiagnosticMessageBuilder;
use App\Service\EngineClient;
use App\Service\ScheduleConstraintBuilder;
use App\Service\ScheduleDiagnosticsRecorder;
use App\Service\ScheduleProgressPublisher;
use App\Service\ScheduleResultImporter;
use App\Service\SolverMetricsMapper;
use App\Service\StructureSnapshotter;
use App\Service\TenantConnectionContext;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;

/**
 * Generation pipeline NR (palier B): an overlay generation carries the closed
 * venue as per-team forbiddenVenueId constraints into the frozen snapshot, never
 * becomes the season baseline, and fails cleanly if its period vanished.
 */
#[Group('phase1')]
#[Group('integration')]
final class OverlayGenerationTest extends KernelTestCase
{
    use TenantGucTrait;

    private const VENUE_CLOSED = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function testClosureOverlaySnapshotCarriesForbiddenVenueAndSkipsBaseline(): void
    {
        [$em, $club, $season, $entry] = $this->seedClosureOverlay('ov-gen');
        $teamIds = array_map(static fn (Team $t): string => $t->getId(), $em->getRepository(Team::class)->findBy(['clubId' => $club->getId()]));

        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Overlay');
        $schedule->setStatus(ScheduleStatus::PENDING);
        $schedule->setCalendarEntryId($entry->getId());
        $em->persist($schedule);
        $em->flush();
        $scheduleId = $schedule->getId();
        $em->clear();
        $this->clearGuc();

        $engineResult = json_encode(['status' => 'completed', 'score' => 5, 'slots' => [], 'diagnostics' => []], \JSON_THROW_ON_ERROR);
        $this->runHandler($em, $club->getId(), $scheduleId, $engineResult);

        $this->scopeGucToClub($club->getId());
        $em->clear();
        $reloaded = $em->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $reloaded);
        self::assertSame(ScheduleStatus::COMPLETED, $reloaded->getStatus());

        // The frozen snapshot carries a forbiddenVenueId constraint per team.
        $constraints = $reloaded->getSnapshotData()['constraints'] ?? [];
        $forbiddenTeams = [];
        foreach ($constraints as $c) {
            if (self::VENUE_CLOSED === ($c['config']['forbiddenVenueId'] ?? null)) {
                $forbiddenTeams[] = $c['scopeTargetId'];
            }
        }
        foreach ($teamIds as $teamId) {
            self::assertContains($teamId, $forbiddenTeams, 'every team must be forbidden from the closed venue');
        }

        // An overlay must never become the season baseline.
        $reloadedSeason = $em->getRepository(Season::class)->find($season->getId());
        self::assertNull($reloadedSeason?->getBaselineScheduleId(), 'an overlay must not become the baseline');
    }

    public function testOverlayWithMissingPeriodFailsCleanly(): void
    {
        [$em, $club, $season, $entry] = $this->seedClosureOverlay('ov-missing');

        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Overlay orphan');
        $schedule->setStatus(ScheduleStatus::PENDING);
        $schedule->setCalendarEntryId($entry->getId());
        $em->persist($schedule);
        $em->flush();
        $scheduleId = $schedule->getId();

        // The period is deleted between queueing and running.
        $em->remove($entry);
        $em->flush();
        $em->clear();
        $this->clearGuc();

        $engineResult = json_encode(['status' => 'completed', 'score' => 0, 'slots' => [], 'diagnostics' => []], \JSON_THROW_ON_ERROR);
        $this->runHandler($em, $club->getId(), $scheduleId, $engineResult);

        $this->scopeGucToClub($club->getId());
        $em->clear();
        $reloaded = $em->getRepository(Schedule::class)->find($scheduleId);
        self::assertSame(ScheduleStatus::FAILED, $reloaded?->getStatus());
        $types = array_map(
            static fn (ScheduleDiagnostic $d): string => $d->getType(),
            $em->getRepository(ScheduleDiagnostic::class)->findBy(['scheduleId' => $scheduleId]),
        );
        self::assertContains('overlay_entry_missing', $types);
    }

    /**
     * @return array{0: EntityManagerInterface, 1: Club, 2: Season, 3: CalendarEntry}
     */
    private function seedClosureOverlay(string $prefix): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('OVGEN Club');
        $club->setSlug($prefix . '-' . $uid);
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

        foreach (['U11', 'U13'] as $name) {
            $team = new Team;
            $team->setClubId($club->getId());
            $team->setSeasonId($season->getId());
            $team->setName($name);
            $team->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc');
            $team->setPriorityTierId(1);
            $em->persist($team);
        }

        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Gym fermé');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $em->persist($entry);

        $dated = new Constraint;
        $dated->setClubId($club->getId());
        $dated->setSeasonId($season->getId());
        $dated->setName('Salle fermée');
        $dated->setScope(ConstraintScope::FACILITY);
        $dated->setScopeTargetId(self::VENUE_CLOSED);
        $dated->setFamily(ConstraintFamily::FACILITY);
        $dated->setRuleType(ConstraintRuleType::HARD);
        $dated->setCalendarEntryId($entry->getId());
        $em->persist($dated);
        $em->flush();

        return [$em, $club, $season, $entry];
    }

    private function runHandler(EntityManagerInterface $em, string $clubId, string $scheduleId, string $engineResult): void
    {
        $container = self::getContainer();
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('id');

        $handler = new GenerateScheduleHandler(
            $em,
            $container->get(ScheduleConstraintBuilder::class),
            $container->get(ScheduleResultImporter::class),
            new EngineClient(new MockHttpClient(new MockResponse($engineResult, ['http_code' => 200]))),
            new ScheduleProgressPublisher($hub),
            new ScheduleDiagnosticsRecorder($em, $container->get(DiagnosticMessageBuilder::class)),
            new SolverMetricsMapper,
            $container->get(ClubGenerationLock::class),
            $container->get(TenantConnectionContext::class),
            $container->get(StructureSnapshotter::class),
        );

        $handler(new GenerateScheduleMessage(scheduleId: $scheduleId, clubId: $clubId));
    }
}
