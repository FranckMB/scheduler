<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Period-editable structure: the TeamPeriodOverride API stamps the tenant/season
 * server-side, scopes the collection to a period, and round-trips activation +
 * sessions-per-week.
 */
#[Group('phase1')]
final class TeamPeriodOverrideApiTest extends WebTestCase
{
    use TenantGucTrait;

    private const PERIOD = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    private const TEAM = 'ffffffff-ffff-4fff-8fff-ffffffffffff';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $token;

    public function testCreateStampsTenantAndListScopesToPeriod(): void
    {
        $created = $this->post(['calendarEntryId' => self::PERIOD, 'teamId' => self::TEAM, 'isActive' => false, 'sessionsPerWeek' => 1]);
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($created['isActive']);
        self::assertSame(1, $created['sessionsPerWeek']);

        // Another period's override must not appear when listing this period.
        $this->post(['calendarEntryId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'teamId' => self::TEAM, 'isActive' => true, 'sessionsPerWeek' => null]);

        $this->client->request('GET', '/api/team_period_overrides?calendarEntryId=' . self::PERIOD, [], [], $this->headers());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $members = $body['member'] ?? [];
        self::assertCount(1, $members, 'the collection is scoped to the requested period');
        self::assertSame(self::TEAM, $members[0]['teamId']);
    }

    public function testUpdateRoundTripsActivation(): void
    {
        $created = $this->post(['calendarEntryId' => self::PERIOD, 'teamId' => self::TEAM, 'isActive' => false, 'sessionsPerWeek' => null]);

        $this->client->request('PUT', '/api/team_period_overrides/' . $created['id'], [], [], $this->headers(), json_encode(['calendarEntryId' => self::PERIOD, 'teamId' => self::TEAM, 'isActive' => true, 'sessionsPerWeek' => 3], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['isActive']);
        self::assertSame(3, $body['sessionsPerWeek']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('TPO ' . $uid)->setSlug('tpo-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('tpo' . $uid . '@test.com')->setFirstName('T')->setLastName('O');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus('active');
        $this->em->persist($this->season);
        $this->em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(array $payload): array
    {
        $this->client->request('POST', '/api/team_period_overrides', [], [], $this->headers(), json_encode($payload, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }
}
