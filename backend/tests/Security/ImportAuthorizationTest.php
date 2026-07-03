<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * SEC-04 non-regression: POST /api/clubs/{id}/import-teams requires an active
 * admin membership in the club named in the path (not just any authenticated
 * user). The tenant listener validates the header/JWT club, not the path {id}.
 */
#[Group('phase1')]
#[Group('integration')]
final class ImportAuthorizationTest extends WebTestCase
{
    private static int $ip = 0;

    private KernelBrowser $client;

    public function testImportOnForeignClubReturns403(): void
    {
        [$tokenA] = $this->register('IMPA');
        [, , $clubB] = $this->register('IMPB');

        $this->client->request('POST', '/api/clubs/' . $clubB . '/import-teams', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
        ]);
        self::assertResponseStatusCodeSame(403, 'a non-member must not import into another club');
    }

    public function testImportAsActiveAdminReaches400WithoutFile(): void
    {
        [$tokenA, , $clubA] = $this->register('IMPC');

        // Guard passed → falls through to "No file uploaded" (400), proving the
        // admin membership check let the request through.
        $this->client->request('POST', '/api/clubs/' . $clubA . '/import-teams', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
        ]);
        self::assertResponseStatusCodeSame(400);
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
        $ip = '10.40.' . intdiv(self::$ip, 254) . '.' . (self::$ip % 254 + 1);
        ++self::$ip;
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'password123',
            'firstName' => 'I', 'lastName' => 'Import', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara,
        ], \JSON_THROW_ON_ERROR));

        $reg = json_decode((string) $this->client->getResponse()->getContent(), true);
        $token = $reg['token'] ?? '';
        self::assertNotSame('', $token, 'registration must return a token');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $me['club']['id']];
    }
}
