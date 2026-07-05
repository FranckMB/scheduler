<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleStatus;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Constraint semantics (NR): a closure overlay expands each closed venue into
 * per-team FACILITY HARD forbiddenVenueId constraints (the shape the engine
 * honors), reuses permanent + dated constraints, and never writes the base
 * schedule-input cache.
 */
#[Group('phase1')]
#[Group('integration')]
final class ScheduleConstraintBuilderOverlayTest extends KernelTestCase
{
    use TenantGucTrait;

    private const VENUE_CLOSED = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private EntityManagerInterface $em;

    private ScheduleConstraintBuilder $builder;

    public function testClosureOverlayExpandsForbiddenVenuePerTeam(): void
    {
        [$club, $season] = $this->seed();
        $teamA = $this->team($club, $season, 'U11');
        $teamB = $this->team($club, $season, 'U13');
        $this->permanentConstraint($club, $season);
        $entry = $this->closurePeriod($club, $season);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $payload = $this->builder->buildForOverlay($schedule, $entry);

        $forbidden = array_values(array_filter(
            $payload['constraints'],
            static fn (array $c): bool => ($c['config']['forbiddenVenueId'] ?? null) === self::VENUE_CLOSED,
        ));
        $forbiddenTeams = array_map(static fn (array $c): string => $c['scopeTargetId'], $forbidden);

        // One forbiddenVenueId constraint per team × closed venue.
        self::assertContains($teamA->getId(), $forbiddenTeams);
        self::assertContains($teamB->getId(), $forbiddenTeams);
        self::assertCount(2, $forbidden);
        foreach ($forbidden as $c) {
            self::assertSame('FACILITY', $c['family']);
            self::assertSame('HARD', $c['ruleType']);
            self::assertSame('TEAM', $c['scope']);
        }

        // Permanent constraint still present (closure is additive).
        $names = array_map(static fn (array $c): string => $c['name'] ?? '', $payload['constraints']);
        self::assertContains('Contrainte permanente', $names);
    }

    public function testHolidayOverlayHasNoForbiddenExpansion(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $this->permanentConstraint($club, $season);
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setTitle('Vacances');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $payload = $this->builder->buildForOverlay($schedule, $entry);

        // Holiday = dated-only: no permanent constraint, no forbidden expansion.
        $names = array_map(static fn (array $c): string => $c['name'] ?? '', $payload['constraints']);
        self::assertNotContains('Contrainte permanente', $names);
        $forbidden = array_filter($payload['constraints'], static fn (array $c): bool => isset($c['config']['forbiddenVenueId']));
        self::assertSame([], array_values($forbidden));
    }

    public function testOverlayBuildDoesNotWriteBaseCache(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $entry = $this->closurePeriod($club, $season);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        /** @var CacheItemPoolInterface&CacheInterface $pool */
        $pool = self::getContainer()->get('cache.schedule');
        $pool->deleteItem(ScheduleConstraintBuilder::cacheKey($club->getId()));

        $this->builder->buildForOverlay($schedule, $entry);

        self::assertFalse(
            $pool->getItem(ScheduleConstraintBuilder::cacheKey($club->getId()))->isHit(),
            'overlay build must not populate the base schedule-input cache',
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
    }

    private function team(Club $club, Season $season, string $name): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setName($name);
        $team->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc');
        $team->setPriorityTierId(1);
        $this->em->persist($team);

        return $team;
    }

    private function permanentConstraint(Club $club, Season $season): void
    {
        $c = new Constraint;
        $c->setClubId($club->getId());
        $c->setSeasonId($season->getId());
        $c->setName('Contrainte permanente');
        $c->setScope(ConstraintScope::CLUB);
        $c->setFamily(ConstraintFamily::TIME);
        $c->setRuleType(ConstraintRuleType::HARD);
        $this->em->persist($c);
    }

    private function closurePeriod(Club $club, Season $season): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Gym fermé');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);

        $dated = new Constraint;
        $dated->setClubId($club->getId());
        $dated->setSeasonId($season->getId());
        $dated->setName('Salle fermée');
        $dated->setScope(ConstraintScope::FACILITY);
        $dated->setScopeTargetId(self::VENUE_CLOSED);
        $dated->setFamily(ConstraintFamily::FACILITY);
        $dated->setRuleType(ConstraintRuleType::HARD);
        $dated->setCalendarEntryId($entry->getId());
        $this->em->persist($dated);

        return $entry;
    }

    private function overlaySchedule(Club $club, Season $season, CalendarEntry $entry): Schedule
    {
        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Overlay');
        $schedule->setStatus(ScheduleStatus::DRAFT);
        $schedule->setCalendarEntryId($entry->getId());
        $this->em->persist($schedule);

        return $schedule;
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('OVB Club');
        $club->setSlug('ovb-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('OVB' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('ovb-' . $uid . '@test.com');
        $user->setFirstName('O');
        $user->setLastName('B');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $this->em->persist($season);

        $this->em->flush();

        return [$club, $season];
    }
}
