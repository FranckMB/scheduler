<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ClubUser;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SEC-01 non-regression: the Club resource is scoped to the caller's active
 * memberships. Club has no club_id column, so this is enforced in
 * ClubStateProvider / ClubStateProcessor, not by the Doctrine tenant filter.
 */
#[Group('phase1')]
#[Group('integration')]
final class ClubAccessTest extends WebTestCase
{
    private KernelBrowser $client;

    public function testCollectionReturnsOnlyOwnClubs(): void
    {
        [$tokenA, , $clubA] = $this->register('CLBA');
        [, , $clubB] = $this->register('CLBB');

        $data = $this->get('/api/clubs', $tokenA);
        self::assertArrayHasKey('member', $data);

        $ids = array_map(static fn (array $c): string => $c['id'], $data['member']);
        self::assertContains($clubA, $ids, 'caller must see its own club');
        self::assertNotContains($clubB, $ids, 'caller must not see another club');
    }

    public function testGetForeignClubReturns404(): void
    {
        [$tokenA] = $this->register('CLBC');
        [, , $clubB] = $this->register('CLBD');

        $this->request('GET', '/api/clubs/' . $clubB, $tokenA);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPutForeignClubReturns404(): void
    {
        [$tokenA] = $this->register('CLBE');
        [, , $clubB] = $this->register('CLBF');

        $this->request('PUT', '/api/clubs/' . $clubB, $tokenA, ['name' => 'Hijacked']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPutOwnClubAsAdminSucceeds(): void
    {
        [$tokenA, , $clubA] = $this->register('CLBG');

        $this->request('PUT', '/api/clubs/' . $clubA, $tokenA, [
            'name' => 'Renamed Club',
            'slug' => 'renamed-' . uniqid(),
            'timezone' => 'Europe/Paris',
            'locale' => 'fr',
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testPutOwnClubAsNonAdminMemberReturns403(): void
    {
        [, , $clubA] = $this->register('CLBJ');
        // A second, active but NON-admin member of the same club.
        $editorToken = $this->addActiveMember($clubA, 'editor');

        $this->request('PUT', '/api/clubs/' . $clubA, $editorToken, [
            'name' => 'Editor Rename',
            'slug' => 'editor-' . uniqid(),
            'timezone' => 'Europe/Paris',
            'locale' => 'fr',
        ]);
        self::assertResponseStatusCodeSame(403, 'an active non-admin member must not edit the club');
    }

    public function testPostClubIsGone(): void
    {
        [$tokenA] = $this->register('CLBH');

        $this->request('POST', '/api/clubs', $tokenA, ['name' => 'Rogue', 'slug' => 'rogue-' . uniqid()]);
        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [404, 405],
            'bare POST /api/clubs must not exist',
        );
    }

    public function testDeleteClubIsGone(): void
    {
        [$tokenA, , $clubA] = $this->register('CLBI');

        $this->request('DELETE', '/api/clubs/' . $clubA, $tokenA);
        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [404, 405],
            'DELETE /api/clubs/{id} must not exist',
        );
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * Seed an active member of $clubId with the given (non-admin) role and
     * return a real JWT for it. Mirrors the register wiring minus the club.
     */
    private function addActiveMember(string $clubId, string $role): string
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $uid = substr(md5(uniqid('', true)), 0, 8);
        $user = new User;
        $user->setEmail($role . $uid . '@test.fr');
        $user->setFirstName('N');
        $user->setLastName('Admin');
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
        // High-entropy IP: the register rate-limiter lives in Redis and is NOT
        // rolled back between test runs, so deterministic IPs eventually throttle.
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'password123',
            'firstName' => 'C', 'lastName' => 'Access', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara,
        ], \JSON_THROW_ON_ERROR));

        $reg = json_decode((string) $this->client->getResponse()->getContent(), true);
        $token = $reg['token'] ?? '';
        self::assertNotSame('', $token, 'registration must return a token');

        $me = $this->get('/api/me', $token);

        return [$token, $me['id'], $me['club']['id']];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $uri, string $token, array $body = []): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], [] === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $uri, string $token): array
    {
        $this->request('GET', $uri, $token);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
