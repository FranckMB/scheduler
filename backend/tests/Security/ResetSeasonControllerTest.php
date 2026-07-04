<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * QW-4: DELETE /api/reset-season wipes all of the caller's club/season data
 * (teams, venues, coaches, constraints, schedules). It is a management action —
 * gated on an active management membership, like ImportController (SEC-04).
 */
#[Group('phase1')]
#[Group('integration')]
final class ResetSeasonControllerTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    public function testResetWipesClubDataForAnAdmin(): void
    {
        [$token, , $clubId] = $this->register('RSTA');
        $this->seedVenues($clubId, 2);

        // Sanity: the seeded venues are visible before the reset.
        $before = $this->getJson('/api/venues', $token);
        self::assertSame(2, $this->totalItems($before), 'seed should expose 2 venues');

        $this->client->request('DELETE', '/api/reset-season', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertGreaterThanOrEqual(2, $body['deleted'], 'the 2 venues must be deleted');

        $after = $this->getJson('/api/venues', $token);
        self::assertSame(0, $this->totalItems($after), 'the club data must be wiped');
    }

    public function testResetRequiresAManagementRole(): void
    {
        [, , $clubId] = $this->register('RSTB');
        $editorToken = $this->addActiveMember($clubId, 'editor');

        // Active member of the club, but not a management role → 403.
        $this->client->request('DELETE', '/api/reset-season', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken]);
        self::assertResponseStatusCodeSame(403, 'a non-management member must not reset the club');
    }

    public function testResetAsOwnerIsAllowed(): void
    {
        [, , $clubId] = $this->register('RSTC');
        $ownerToken = $this->addActiveMember($clubId, 'owner');

        $this->client->request('DELETE', '/api/reset-season', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $ownerToken]);
        self::assertResponseIsSuccessful();
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function seedVenues(string $clubId, int $count): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->scopeGucToClub($clubId);
        $season = $em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertInstanceOf(Season::class, $season);

        for ($i = 0; $i < $count; ++$i) {
            $venue = new Venue;
            $venue->setClubId($clubId);
            $venue->setSeasonId($season->getId());
            $venue->setName(\sprintf('Gymnase %d', $i));
            $venue->setSource('manual');
            $em->persist($venue);
        }
        $em->flush();
    }

    /** @param array<string, mixed> $json */
    private function totalItems(array $json): int
    {
        $total = $json['totalItems'] ?? $json['hydra:totalItems'] ?? null;
        if (\is_int($total)) {
            return $total;
        }
        $member = $json['member'] ?? $json['hydra:member'] ?? [];

        return \is_array($member) ? \count($member) : 0;
    }

    /** @return array<string, mixed> */
    private function getJson(string $uri, string $token): array
    {
        $this->client->request('GET', $uri, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function addActiveMember(string $clubId, string $role): string
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $uid = substr(md5(uniqid('', true)), 0, 8);
        $user = new User;
        $user->setEmail($role . $uid . '@test.fr');
        $user->setFirstName('N');
        $user->setLastName('Member');
        $user->setPasswordHash($hasher->hashPassword($user, 'password123'));
        $em->persist($user);

        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $em->persist($membership);
        $em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, userId, clubId]
     */
    private function register(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'password123',
            'firstName' => 'R', 'lastName' => 'Reset', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara,
        ], \JSON_THROW_ON_ERROR));

        $reg = json_decode((string) $this->client->getResponse()->getContent(), true);
        $token = $reg['token'] ?? '';
        self::assertNotSame('', $token, 'registration must return a token');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $me['club']['id']];
    }
}
