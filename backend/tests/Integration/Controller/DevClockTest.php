<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\VerifiesRegistration;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The dev clock endpoint is authenticated, routed, and shaped as the frontend
 * widget expects (now + pinned). The actual clock shift is dev-only (the
 * SimulatedClock override is wired in the dev env) and covered by manual e2e.
 */
#[Group('integration')]
final class DevClockTest extends WebTestCase
{
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testGetReturnsClockState(): void
    {
        $token = $this->register();
        $this->client->request('GET', '/api/dev/clock', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('now', $body);
        self::assertArrayHasKey('pinned', $body);
    }

    public function testResetReportsUnpinned(): void
    {
        $token = $this->register();
        $this->client->request('POST', '/api/dev/clock', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['at' => null], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertFalse($body['pinned']);
    }

    public function testInvalidDateIsRejected(): void
    {
        $token = $this->register();
        $this->client->request('POST', '/api/dev/clock', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['at' => 'not-a-date'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
    }

    public function testRequiresAuth(): void
    {
        $this->client->request('GET', '/api/dev/clock');
        self::assertResponseStatusCodeSame(401);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function register(): string
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'clk' . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'C', 'lastName' => 'Clock', 'ara' => strtoupper($suffix), 'club_name' => 'Club Clock', 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        return $token;
    }
}
