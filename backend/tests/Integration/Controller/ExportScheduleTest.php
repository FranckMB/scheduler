<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The schedule export endpoints (PDF/PNG async + XLSX sync) are authenticated,
 * routed, and fail-closed on an unknown schedule. The tenant scoping + actual
 * file production are covered by ExportPdfHandlerRlsTest and manual e2e.
 */
#[Group('integration')]
final class ExportScheduleTest extends WebTestCase
{
    private KernelBrowser $client;

    public function testExportXlsxRequiresAuth(): void
    {
        $this->client->request('POST', '/api/schedules/' . $this->uuid() . '/export-xlsx');
        self::assertResponseStatusCodeSame(401, 'export must be behind the JWT firewall');
    }

    public function testExportPdfOnUnknownScheduleReturns404(): void
    {
        $token = $this->register();
        $this->client->request('POST', '/api/schedules/' . $this->uuid() . '/export-pdf', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ], '{}');
        self::assertResponseStatusCodeSame(404);
    }

    public function testExportXlsxOnUnknownScheduleReturns404(): void
    {
        $token = $this->register();
        $this->client->request('POST', '/api/schedules/' . $this->uuid() . '/export-xlsx', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ], '{}');
        self::assertResponseStatusCodeSame(404, 'the xlsx route is wired and fail-closed on a missing schedule');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function uuid(): string
    {
        return \sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFFFFFFFFFF));
    }

    private function register(): string
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'exp' . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'E', 'lastName' => 'Export', 'ara' => strtoupper($suffix), 'club_name' => 'Club Export',
        ], \JSON_THROW_ON_ERROR));

        $reg = json_decode((string) $this->client->getResponse()->getContent(), true);
        $token = $reg['token'] ?? '';
        self::assertNotSame('', $token, 'registration must return a token');

        return $token;
    }
}
