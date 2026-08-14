<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\Season;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\ScheduleDiagnosticsRecorder;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR — axe §7.1 « generation pipeline (import) » : le pinpoint d'un `conflict` traverse l'import.
 *
 * L'engine (schéma 2.6) porte déjà `dayOfWeek`/`startTime` sur un diagnostic `conflict` — le
 * jour + l'heure de la séance fautive. La perte était 100 % côté import : le recorder ne mappait
 * que team/coach/venue. Ce test garde que ces deux champs entrent maintenant dans les colonnes,
 * DANS LES DEUX CASSES (l'engine émet camelCase, un payload nu peut être snake_case), tandis que
 * les 10 autres types de diagnostic les laissent NULL — et que les mappages existants
 * (team/coach/venue, sévérité) + la purge restent inchangés.
 *
 * Additive display data → pas de step `blocking-tests` : tourne dans `unit-tests` (phpunit tests/).
 */
#[Group('phase1')]
#[Group('integration')]
final class ScheduleDiagnosticsRecorderTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const string VENUE_ID = '11111111-1111-4111-8111-111111111111';

    private const string TEAM_ID = '22222222-2222-4222-8222-222222222222';

    private const string COACH_ID = '33333333-3333-4333-8333-333333333333';

    private EntityManagerInterface $em;

    private ScheduleDiagnosticsRecorder $recorder;

    public function testAConflictWithCamelCaseDayAndTimeFillsTheColumns(): void
    {
        $schedule = $this->seedSchedule();

        $this->recorder->record($schedule, ['diagnostics' => [[
            'type' => 'conflict',
            'severity' => 'ERROR',
            'venueId' => self::VENUE_ID,
            'dayOfWeek' => 6,
            'startTime' => '10:00',
            'message' => 'Le gymnase accueille 2 équipes en même temps.',
        ]]]);
        $this->em->flush();

        $diagnostic = $this->onlyDiagnostic($schedule);
        self::assertSame('conflict', $diagnostic->getType());
        self::assertSame(ScheduleDiagnosticSeverity::ERROR, $diagnostic->getSeverity());
        self::assertSame(self::VENUE_ID, $diagnostic->getVenueId());
        self::assertSame(6, $diagnostic->getDayOfWeek());
        self::assertSame('10:00', $diagnostic->getStartTime());
    }

    public function testASnakeCaseDayAndTimeAreAlsoAccepted(): void
    {
        $schedule = $this->seedSchedule();

        $this->recorder->record($schedule, ['diagnostics' => [[
            'type' => 'conflict',
            'severity' => 'ERROR',
            'venue_id' => self::VENUE_ID,
            'day_of_week' => 3,
            'start_time' => '18:30',
            'message' => 'Conflit de capacité.',
        ]]]);
        $this->em->flush();

        $diagnostic = $this->onlyDiagnostic($schedule);
        self::assertSame(self::VENUE_ID, $diagnostic->getVenueId());
        self::assertSame(3, $diagnostic->getDayOfWeek());
        self::assertSame('18:30', $diagnostic->getStartTime());
    }

    public function testADiagnosticWithoutDayAndTimeLeavesColumnsNull(): void
    {
        $schedule = $this->seedSchedule();

        $this->recorder->record($schedule, ['diagnostics' => [[
            'type' => 'unplaced',
            'severity' => 'WARNING',
            'teamId' => self::TEAM_ID,
            'message' => 'Équipe non placée.',
        ]]]);
        $this->em->flush();

        $diagnostic = $this->onlyDiagnostic($schedule);
        self::assertSame(self::TEAM_ID, $diagnostic->getTeamId());
        self::assertNull($diagnostic->getDayOfWeek(), 'Un diagnostic sans jour/heure laisse les colonnes NULL.');
        self::assertNull($diagnostic->getStartTime());
    }

    public function testExistingTeamCoachVenueMappingsAreUnchanged(): void
    {
        $schedule = $this->seedSchedule();

        $this->recorder->record($schedule, ['diagnostics' => [[
            'type' => 'coach_overload',
            'severity' => 'WARNING',
            'team_id' => self::TEAM_ID,
            'coach_id' => self::COACH_ID,
            'venue_id' => self::VENUE_ID,
            'message' => 'Coach surchargé.',
        ]]]);
        $this->em->flush();

        $diagnostic = $this->onlyDiagnostic($schedule);
        self::assertSame(self::TEAM_ID, $diagnostic->getTeamId());
        self::assertSame(self::COACH_ID, $diagnostic->getCoachId());
        self::assertSame(self::VENUE_ID, $diagnostic->getVenueId());
        self::assertSame(ScheduleDiagnosticSeverity::WARNING, $diagnostic->getSeverity());
        self::assertNull($diagnostic->getDayOfWeek());
        self::assertNull($diagnostic->getStartTime());
    }

    public function testPurgePreviousRemovesEarlierRunsForThisSchedule(): void
    {
        $schedule = $this->seedSchedule();

        $this->recorder->record($schedule, ['diagnostics' => [[
            'type' => 'conflict',
            'severity' => 'ERROR',
            'venueId' => self::VENUE_ID,
            'dayOfWeek' => 6,
            'startTime' => '10:00',
            'message' => 'Premier run.',
        ]]]);
        $this->em->flush();
        self::assertCount(1, $this->diagnostics($schedule));

        $this->recorder->purgePrevious($schedule);
        $this->em->flush();

        self::assertCount(0, $this->diagnostics($schedule), 'purgePrevious efface les diagnostics du run précédent.');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->recorder = self::getContainer()->get(ScheduleDiagnosticsRecorder::class);
    }

    private function seedSchedule(): Schedule
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('C ' . $uid)->setSlug('c-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')
            ->setOnboardingCompleted(true)->setFfbbClubCode('PSI' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('S')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    /** @return list<ScheduleDiagnostic> */
    private function diagnostics(Schedule $schedule): array
    {
        $this->em->clear();

        return array_values($this->em->getRepository(ScheduleDiagnostic::class)->findBy(['scheduleId' => $schedule->getId()]));
    }

    private function onlyDiagnostic(Schedule $schedule): ScheduleDiagnostic
    {
        $found = $this->diagnostics($schedule);
        self::assertCount(1, $found);

        return $found[0];
    }
}
