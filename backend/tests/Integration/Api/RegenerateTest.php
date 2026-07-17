<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * planning-versions (décision 6, clause 2) non-regression: the plain "Régénérer"
 * creates a NEW linear version (a new Schedule row) instead of overwriting the
 * source in place, and leaves the source version untouched. HARD locks survive
 * via the generation payload (findBaseSlotTemplates re-pins every base version's
 * HARD slots) — the controller copies nothing. Guards mirror RegenerateFromVersion.
 */
#[Group('phase1')]
final class RegenerateTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $token;

    public function testRegenerateCreatesANewPendingVersionAndLeavesTheSourceUntouched(): void
    {
        $source = $this->seedSchedule(ScheduleStatus::COMPLETED);
        $this->seedSlot($source, LockLevel::HARD);
        $this->seedSlot($source, LockLevel::NONE);
        $this->em->flush();

        $this->post($source->getId());
        self::assertResponseStatusCodeSame(202);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $newId = $body['id'] ?? '';
        self::assertNotSame('', $newId);
        self::assertNotSame($source->getId(), $newId, 'a NEW schedule row is created, not the source');

        $this->em->clear();
        $new = $this->em->getRepository(Schedule::class)->find($newId);
        self::assertSame(ScheduleStatus::PENDING, $new?->getStatus());

        // The new version starts empty — the solver fills it; the source's HARD
        // pins reach it through the generation payload, not a controller copy.
        self::assertCount(0, $this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $newId]));
        // The source version is left fully untouched (both slots intact).
        self::assertCount(2, $this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $source->getId()]));
    }

    public function testTheChosenVersionIsRefused(): void
    {
        $source = $this->seedSchedule(ScheduleStatus::COMPLETED);
        $this->em->flush();
        $this->choosePlanVersion($source);

        $this->post($source->getId());
        self::assertResponseStatusCodeSame(409, 'the version the plan points at is read-only — reopen it first');
    }

    public function testDraftIsRefused(): void
    {
        // The first generation (a DRAFT) is the wizard's in-place path, not a regenerate.
        $source = $this->seedSchedule(ScheduleStatus::DRAFT);
        $this->em->flush();

        $this->post($source->getId());
        self::assertResponseStatusCodeSame(409);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('Regen ' . $uid)->setSlug('regen-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('regen' . $uid . '@test.com')->setFirstName('R')->setLastName('G');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));

        // A real season: the versions live in its SEASON plan, and the pointer is
        // what says "in force". Fabricating a seasonId would leave them plan-less.
        $year = \App\Service\SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $this->season = (new Season)
            ->setClubId($this->club->getId())
            ->setName((string) $year)
            ->setStartDate(new DateTimeImmutable($year . '-08-01'))
            ->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'))
            ->setStatus('active');
        $this->season->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();
        $this->provisionSeasonPlan($this->season);

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function post(string $scheduleId): void
    {
        $this->client->request('POST', '/api/schedules/' . $scheduleId . '/regenerate', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
        ]);
    }

    private function seedSchedule(ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($this->club->getId())
            ->setSeasonId($this->season->getId())
            ->setName('V source')
            ->setStatus($status);
        $this->em->persist($schedule);
        $this->em->flush();
        // Prod links every version at creation ; sans ça, depuis C4 le site « socle ? »
        // du /regenerate lèverait sur une version sans plan.
        $this->linkSeededSchedule($schedule);

        return $schedule;
    }

    private function seedSlot(Schedule $schedule, LockLevel $lock): void
    {
        $slot = (new ScheduleSlotTemplate)
            ->setClubId($this->club->getId())
            ->setSeasonId($schedule->getSeasonId())
            ->setScheduleId($schedule->getId())
            ->setTeamId($this->newUuid())
            ->setVenueId($this->newUuid())
            ->setDayOfWeek(2)
            ->setStartTime(new DateTimeImmutable('18:00'))
            ->setDurationMinutes(90)
            ->setLockLevel($lock);
        $this->em->persist($slot);
    }

    private function newUuid(): string
    {
        return \sprintf('%s-%s-4%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));
    }
}
