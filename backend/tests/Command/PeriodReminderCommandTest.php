<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PeriodReminderCommand;
use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Repository\CalendarEntryRepository;
use App\Repository\ClubUserRepository;
use App\Repository\SeasonRepository;
use App\Service\PeriodReminderMailBuilder;
use App\Service\TenantConnectionContext;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * The reminder cron emails a club's managers about periods without an overlay
 * plan at J-14/J-7/J-3 — never auto-acting, tenant-isolated, and resilient to a
 * single club failing.
 */
#[Group('phase1')]
#[Group('integration')]
final class PeriodReminderCommandTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    /** @var list<string> recipient emails captured by the spy mailer */
    private array $sentTo = [];

    /** @var list<string> subjects captured by the spy mailer */
    private array $sentSubjects = [];

    private string $throwForRecipient = '';

    public function testRemindsManagersAtEachThreshold(): void
    {
        foreach ([14, 7, 3] as $days) {
            $this->sentTo = [];
            $this->sentSubjects = [];
            [, $season, $admin] = $this->seedClub('T' . $days);
            $this->period($season, $this->today()->modify("+{$days} days"));
            $this->em->flush();

            $this->runCommand('2026-01-15');

            self::assertContains($admin, $this->sentTo, "J-{$days} must email the manager");
        }
    }

    public function testJ3SubjectIsRed(): void
    {
        [, $season] = $this->seedClub('RED');
        $this->period($season, $this->today()->modify('+3 days'));
        $this->em->flush();

        $this->runCommand('2026-01-15');

        self::assertNotEmpty($this->sentSubjects);
        self::assertStringStartsWith('🔴', $this->sentSubjects[0]);
    }

    public function testNoReminderWithOverlayOrOffThreshold(): void
    {
        [, $season, $admin] = $this->seedClub('OFF');
        $this->period($season, $this->today()->modify('+14 days'), overlay: true); // has plan
        $this->period($season, $this->today()->modify('+10 days')); // off threshold
        $this->period($season, $this->today()->modify('-1 days')); // already started
        $this->period($season, $this->today()->modify('+7 days'), status: CalendarEntryStatus::IGNORED); // ignored
        $this->em->flush();

        $this->runCommand('2026-01-15');

        self::assertNotContains($admin, $this->sentTo);
        self::assertSame([], $this->sentTo);
    }

    public function testDryRunSendsNothing(): void
    {
        [, $season] = $this->seedClub('DRY');
        $this->period($season, $this->today()->modify('+14 days'));
        $this->em->flush();

        $tester = $this->runCommand('2026-01-15', ['--dry-run' => true]);

        self::assertSame([], $this->sentTo);
        self::assertStringContainsString('would remind', $tester->getDisplay());
    }

    public function testTenantIsolationBetweenClubs(): void
    {
        [, $seasonA, $adminA] = $this->seedClub('ISOA');
        [, $seasonB, $adminB] = $this->seedClub('ISOB');
        $this->seedClub('ISOC'); // no period → must get nothing
        $this->period($seasonA, $this->today()->modify('+14 days'));
        $this->period($seasonB, $this->today()->modify('+7 days'));
        $this->em->flush();

        $this->runCommand('2026-01-15');

        // Each manager only receives their own club's reminder.
        self::assertSame([$adminA], array_values(array_unique(array_filter($this->sentTo, static fn (string $e): bool => $e === $adminA))));
        self::assertContains($adminA, $this->sentTo);
        self::assertContains($adminB, $this->sentTo);
        self::assertCount(2, $this->sentTo);
    }

    public function testResilientToAFailingClub(): void
    {
        [, $seasonA, $adminA] = $this->seedClub('FAILA');
        [, $seasonB, $adminB] = $this->seedClub('FAILB');
        $this->period($seasonA, $this->today()->modify('+14 days'));
        $this->period($seasonB, $this->today()->modify('+14 days'));
        $this->em->flush();

        // The mailer throws for club A's manager; club B must still be reminded.
        $this->throwForRecipient = $adminA;
        $this->runCommand('2026-01-15');

        self::assertNotContains($adminA, $this->sentTo);
        self::assertContains($adminB, $this->sentTo);
    }

    public function testOnlyManagementRolesAreEmailed(): void
    {
        [$club, $season, $admin] = $this->seedClub('ROLE');
        $this->addMember($club, 'editor@club.fr', 'editor', true);
        $this->addMember($club, 'viewer@club.fr', 'viewer', true);
        $this->addMember($club, 'inactive@club.fr', 'admin', false);
        $this->period($season, $this->today()->modify('+14 days'));
        $this->em->flush();

        $this->runCommand('2026-01-15');

        self::assertSame([$admin], $this->sentTo);
    }

    public function testDailyIdempotence(): void
    {
        [, $season, $admin] = $this->seedClub('IDEM');
        $this->period($season, $this->today()->modify('+14 days'));
        $this->em->flush();

        // Runs the day after: the J-14 period is now J-13, no threshold matches.
        $this->runCommand('2026-01-16');

        self::assertNotContains($admin, $this->sentTo);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-15 00:00:00');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCommand(string $date, array $options = []): CommandTester
    {
        $container = self::getContainer();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (RawMessage $message): void {
            if ($message instanceof Email) {
                $to = $message->getTo()[0]->getAddress();
                if ('' !== $this->throwForRecipient && $to === $this->throwForRecipient) {
                    throw new RuntimeException('smtp down');
                }
                $this->sentTo[] = $to;
                $this->sentSubjects[] = (string) $message->getSubject();
            }
        });

        $command = new PeriodReminderCommand(
            $this->em,
            $container->get(TenantConnectionContext::class),
            $mailer,
            $container->get(CalendarEntryRepository::class),
            $container->get(ClubUserRepository::class),
            $container->get(SeasonRepository::class),
            new PeriodReminderMailBuilder('http://localhost:5173'),
        );

        $tester = new CommandTester($command);
        $tester->execute(['--date' => $date, ...$options]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    private function period(Season $season, DateTimeImmutable $start, bool $overlay = false, CalendarEntryStatus $status = CalendarEntryStatus::ACTIVE): void
    {
        $this->scopeGucToClub($season->getClubId());
        $entry = new CalendarEntry;
        $entry->setClubId($season->getClubId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setStatus($status);
        $entry->setTitle('Gym fermé');
        $entry->setStartDate($start);
        $entry->setEndDate($start->modify('+6 days'));
        if ($overlay) {
            $entry->setOverlayScheduleId('99999999-9999-4999-8999-999999999999');
        }
        $this->em->persist($entry);
        // Flush under THIS club's GUC (RLS WITH CHECK) before another club scopes it.
        $this->em->flush();
    }

    private function addMember(Club $club, string $email, string $role, bool $active): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User;
        $user->setEmail($email);
        $user->setFirstName('X');
        $user->setLastName('Y');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole($role);
        $cu->setIsActive($active);
        $this->em->persist($cu);
        $this->em->flush();
    }

    /**
     * @return array{0: Club, 1: Season, 2: string} club, season, admin email
     */
    private function seedClub(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);
        $this->em->flush();

        $adminEmail = 'admin-' . $tag . '-' . $uid . '@club.fr';
        $this->addMember($club, $adminEmail, 'admin', true);

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season, $adminEmail];
    }
}
