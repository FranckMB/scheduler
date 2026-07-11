<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\VerifiesRegistration;
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
    use VerifiesRegistration;

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

    public function testPutSelfSucceeds(): void
    {
        [$token, $userId] = $this->register('USRI');

        $this->request('PUT', '/api/users/' . $userId, $token, ['firstName' => 'Renamed', 'lastName' => 'Self']);
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

    public function testDeleteIsGone(): void
    {
        [$tokenA, $userA] = $this->register('USRG');

        // No Delete operation is exposed (would orphan ClubUser memberships).
        $this->request('DELETE', '/api/users/' . $userA, $tokenA);
        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [404, 405],
            'DELETE /api/users/{id} must not exist',
        );
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
        // High-entropy IP: the register rate-limiter lives in Redis and is NOT
        // rolled back between test runs, so deterministic IPs eventually throttle.
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'U', 'lastName' => 'Self', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id']];
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
