<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ScheduleStatus;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * NR — planning lifecycle guards (§7.1): the version a plan POINTS at is read-only
 * (no delete, no free-form PUT), and status never changes through PUT (transitions
 * belong to generate/validate/reopen). ADR-0002: "baseline" and "validated" used to
 * be two guards over two mirrors — they are now one, keyed on the pointer alone.
 */
#[Group('phase1')]
#[Group('integration')]
final class ScheduleLifecycleGuardTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    public function testTheChosenVersionCannotBeDeleted(): void
    {
        // The season's calendar cannot be deleted out from under the club: it is
        // the version the plan points at. Reopen first, then delete.
        [$user, $club, $season] = $this->seed('SLG1');
        $schedule = $this->makeSchedule($club, $season, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($schedule);

        $this->client->request('DELETE', "/api/schedules/{$schedule->getId()}", [], [], $this->authHeaders($user, $club));

        self::assertResponseStatusCodeSame(409, 'the version the plan points at must never be deletable');
        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($schedule->getId()));
    }

    public function testAnUnchosenCompletedVersionIsDeletable(): void
    {
        [$user, $club, $season] = $this->seed('SLG3');
        // Deux versions : celle qu'on supprime n'est pas la dernière, donc la saison
        // reste ancrée — c'est bien « une version de travail ordinaire » qu'on teste.
        $this->makeSchedule($club, $season, ScheduleStatus::COMPLETED);
        $schedule = $this->makeSchedule($club, $season, ScheduleStatus::COMPLETED);

        $this->client->request('DELETE', "/api/schedules/{$schedule->getId()}", [], [], $this->authHeaders($user, $club));

        self::assertResponseStatusCodeSame(204);
    }

    public function testTheLastFinishedSeasonVersionCannotBeDeleted(): void
    {
        // Décision fondateur : le plan SEASON est la base de tout. Rouvrir dépointe
        // (inv. 2), mais ne doit pas rendre supprimable le SEUL planning de la saison —
        // sinon un clic renvoie un club établi dans le wizard guidé, matchs orphelins.
        [$user, $club, $season] = $this->seed('SLG8');
        $schedule = $this->makeSchedule($club, $season, ScheduleStatus::COMPLETED);

        $this->client->request('DELETE', "/api/schedules/{$schedule->getId()}", [], [], $this->authHeaders($user, $club));

        self::assertResponseStatusCodeSame(409, 'la dernière version terminée ancre la saison');
        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($schedule->getId()));
    }

    public function testAFailedVersionIsDeletableEvenAlone(): void
    {
        // La garde protège le CALENDRIER de la saison, pas n'importe quelle ligne : un
        // solve en échec n'est pas un planning, il n'ancre rien.
        [$user, $club, $season] = $this->seed('SLG9');
        $schedule = $this->makeSchedule($club, $season, ScheduleStatus::FAILED);

        $this->client->request('DELETE', "/api/schedules/{$schedule->getId()}", [], [], $this->authHeaders($user, $club));

        self::assertResponseStatusCodeSame(204);
    }

    public function testGeneratingScheduleCannotBeDeleted(): void
    {
        // planning-versions D1: a version whose solve is still running cannot
        // be deleted out from under the worker (its import would resurrect
        // artifacts on a dead schedule id).
        [$user, $club, $season] = $this->seed('SLG6');
        $schedule = $this->makeSchedule($club, $season, ScheduleStatus::GENERATING);

        $this->client->request('DELETE', "/api/schedules/{$schedule->getId()}", [], [], $this->authHeaders($user, $club));

        self::assertResponseStatusCodeSame(409);
    }

    public function testStatusIsIgnoredOnPut(): void
    {
        [$user, $club, $season] = $this->seed('SLG4');
        $schedule = $this->makeSchedule($club, $season, ScheduleStatus::DRAFT);

        // Fabricating a COMPLETED plan without any generation must be impossible —
        // the PUT succeeds (a stale echoed status must not break renames) but the
        // status field is never applied.
        $this->put($user, $club, $schedule->getId(), ['name' => 'Fake', 'status' => 'COMPLETED']);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        $reloaded = $this->em->getRepository(Schedule::class)->find($schedule->getId());
        self::assertNotNull($reloaded);
        self::assertSame(ScheduleStatus::DRAFT, $reloaded->getStatus(), 'status must never change through PUT');
        self::assertSame('Fake', $reloaded->getName());
    }

    public function testRenameEchoingCurrentStatusIsAccepted(): void
    {
        [$user, $club, $season] = $this->seed('SLG5');
        $schedule = $this->makeSchedule($club, $season, ScheduleStatus::COMPLETED);

        // The frontend rename echoes the current status — that must keep working.
        $this->put($user, $club, $schedule->getId(), ['name' => 'Nouveau nom', 'status' => 'COMPLETED']);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Nouveau nom', $data['name']);
        self::assertSame('COMPLETED', $data['status']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get('security.user_password_hasher');
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user, Club $club): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'HTTP_X-Club-Id' => $club->getId(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function put(User $user, Club $club, string $id, array $payload): void
    {
        $this->client->request('PUT', "/api/schedules/{$id}", [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    private function makeSchedule(Club $club, Season $season, ScheduleStatus $status): Schedule
    {
        $this->scopeGucToClub($club->getId());
        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Plan');
        $schedule->setStatus($status);
        $this->em->persist($schedule);
        $this->em->flush();
        // Prod links every version at creation ; sans ça, depuis C4 la garde de
        // suppression (planIsSeason) ne reconnaîtrait pas la dernière version de saison.
        $this->linkSeededSchedule($schedule);

        return $schedule;
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seed(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . strtolower($tag) . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('user-' . strtolower($tag) . '-' . $uid . '@test.com');
        $user->setFirstName('S');
        $user->setLastName('G');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
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

        return [$user, $club, $season];
    }
}
