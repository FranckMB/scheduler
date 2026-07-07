<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * SEC-11 non-regression: the authenticated API is rate-limited per user. The
 * audit measured 100 authenticated GETs with zero throttling; this pins that a
 * 429 now fires past the budget (test limit 30/min, see rate_limiter.yaml
 * when@test) and that the throttle is scoped to the offending user only.
 */
#[Group('phase1')]
#[Group('integration')]
final class ApiRateLimitTest extends WebTestCase
{
    private const TEST_LIMIT = 30;

    private KernelBrowser $client;

    public function testAuthenticatedApiIsThrottledPastTheBudget(): void
    {
        [$token] = $this->register('RL1');

        $statuses = [];
        for ($i = 0; $i < self::TEST_LIMIT + 3; ++$i) {
            $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            $statuses[] = $this->client->getResponse()->getStatusCode();
        }

        self::assertSame(200, $statuses[0], 'the first request is within budget');
        self::assertContains(429, $statuses, 'the API must throttle a runaway client');
        self::assertSame(429, $statuses[self::TEST_LIMIT + 2], 'once tripped, further requests stay throttled');
    }

    public function testThrottleIsPerUserNotGlobal(): void
    {
        // Exhaust user A, then user B (fresh) must still be served — the limiter
        // keys on the user, so one abuser does not lock out the whole club/API.
        [$tokenA] = $this->register('RL2');
        for ($i = 0; $i < self::TEST_LIMIT + 1; ++$i) {
            $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA]);
        }
        self::assertSame(429, $this->client->getResponse()->getStatusCode(), 'user A is throttled');

        [$tokenB] = $this->register('RL3');
        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'user B is unaffected');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
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
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'R', 'lastName' => 'Limit', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara,
        ], \JSON_THROW_ON_ERROR));

        $reg = json_decode((string) $this->client->getResponse()->getContent(), true);
        $token = $reg['token'] ?? '';
        self::assertNotSame('', $token, 'registration must return a token');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $me['club']['id']];
    }
}
