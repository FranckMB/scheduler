<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * SEC-02 non-regression: the User resource is self-only. No collection (email
 * enumeration), no bare POST; Get/Put/Delete restricted to the caller's own id.
 */
#[Group('phase1')]
#[Group('integration')]
final class UserSelfOnlyTest extends WebTestCase
{
    private static int $ip = 0;

    private KernelBrowser $client;

    public function testUsersCollectionIsGone(): void
    {
        [$token] = $this->register('USRA');

        $this->request('GET', '/api/users', $token);
        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [404, 405],
            'GET /api/users collection must not exist',
        );
    }

    public function testGetSelfSucceeds(): void
    {
        [$token, $userId] = $this->register('USRB');

        $this->request('GET', '/api/users/' . $userId, $token);
        self::assertResponseIsSuccessful();
    }

    public function testGetOtherUserReturns404(): void
    {
        [$tokenA] = $this->register('USRC');
        [, $userB] = $this->register('USRD');

        $this->request('GET', '/api/users/' . $userB, $tokenA);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPutOtherUserReturns404(): void
    {
        [$tokenA] = $this->register('USRE');
        [, $userB] = $this->register('USRF');

        $this->request('PUT', '/api/users/' . $userB, $tokenA, ['firstName' => 'Hijack']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteOtherUserReturns404(): void
    {
        [$tokenA] = $this->register('USRG');
        [, $userB] = $this->register('USRH');

        $this->request('DELETE', '/api/users/' . $userB, $tokenA);
        self::assertResponseStatusCodeSame(404);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * @return array{0: string, 1: string} [token, userId]
     */
    private function register(string $ara): array
    {
        $ip = '10.30.' . intdiv(self::$ip, 254) . '.' . (self::$ip % 254 + 1);
        ++self::$ip;
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'password123',
            'firstName' => 'U', 'lastName' => 'Self', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara,
        ], \JSON_THROW_ON_ERROR));

        $reg = json_decode((string) $this->client->getResponse()->getContent(), true);
        $token = $reg['token'] ?? '';
        self::assertNotSame('', $token, 'registration must return a token');

        return [$token, $reg['user']['id']];
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
}
