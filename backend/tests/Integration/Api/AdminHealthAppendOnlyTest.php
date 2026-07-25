<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\SuperAdmin;
use App\Security\TotpService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Append-only contract: GET /api/admin/health must keep every pre-existing
 * key (status, checkedAt, services.{database,redis,engine,mercure,worker},
 * messenger) AND surface the new keys (containers, externalDependencies,
 * restartAllowed) added by Wave A. Any future addition must not drop, rename
 * or repurpose the previous surface.
 */
#[Group('phase1')]
#[Group('integration')]
final class AdminHealthAppendOnlyTest extends WebTestCase
{
    private KernelBrowser $client;

    private string $adminId;

    private string $requestIp;

    public function testHealthEndpointKeepsAllPreExistingKeysAndAddsNewOnes(): void
    {
        [$secret] = $this->createSuperAdmin('append-only@example.test', 'VeryStrongPassword!');
        $this->authenticate('append-only@example.test', 'VeryStrongPassword!', $secret);

        $this->client->request('GET', '/api/admin/health');
        self::assertResponseIsSuccessful();

        $health = $this->responseBody();

        // --- Pre-existing keys (must NEVER be renamed or removed) ---

        self::assertArrayHasKey('status', $health);
        self::assertContains($health['status'], ['healthy', 'degraded']);
        self::assertIsString($health['status']);

        self::assertArrayHasKey('checkedAt', $health);
        self::assertIsString($health['checkedAt']);
        self::assertNotEmpty($health['checkedAt']);

        self::assertArrayHasKey('services', $health);
        self::assertIsArray($health['services']);
        foreach (['database', 'redis', 'engine', 'worker', 'mercure'] as $service) {
            self::assertArrayHasKey($service, $health['services'], "services.$service is required");
            self::assertIsArray($health['services'][$service]);
            self::assertArrayHasKey('status', $health['services'][$service]);
            self::assertContains($health['services'][$service]['status'], ['up', 'down', 'unknown']);
        }

        self::assertArrayHasKey('messenger', $health);
        self::assertIsArray($health['messenger']);
        self::assertArrayHasKey('status', $health['messenger']);
        self::assertContains($health['messenger']['status'], ['up', 'degraded', 'unknown']);
        self::assertArrayHasKey('backlog', $health['messenger']);
        self::assertArrayHasKey('failed', $health['messenger']);
        self::assertArrayHasKey('retriesToday', $health['messenger']);

        // --- New keys (Wave A — admin console tabs & containers) ---

        self::assertArrayHasKey('containers', $health);
        self::assertIsArray($health['containers']);
        self::assertNotEmpty($health['containers'], 'containers must list at least one entry');
        foreach ($health['containers'] as $entry) {
            self::assertIsArray($entry);
            self::assertArrayHasKey('key', $entry);
            self::assertIsString($entry['key']);
            self::assertArrayHasKey('name', $entry);
            self::assertIsString($entry['name']);
            self::assertArrayHasKey('status', $entry);
            self::assertIsString($entry['status']);
        }

        self::assertArrayHasKey('externalDependencies', $health);
        self::assertIsArray($health['externalDependencies']);
        self::assertCount(3, $health['externalDependencies'], 'externalDependencies must hold the 3 ffbb/ods/etalab probes');
        foreach ($health['externalDependencies'] as $entry) {
            self::assertIsArray($entry);
            self::assertArrayHasKey('key', $entry);
            self::assertIsString($entry['key']);
            self::assertArrayHasKey('name', $entry);
            self::assertIsString($entry['name']);
            self::assertArrayHasKey('status', $entry);
            self::assertContains($entry['status'], ['up', 'down', 'unknown']);
        }
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->requestIp = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }

    protected function tearDown(): void
    {
        if (isset($this->adminId)) {
            $this->admin()->executeStatement('DELETE FROM admin_audit_log WHERE super_admin_id = :id OR super_admin_id IS NULL', ['id' => $this->adminId]);
            $this->admin()->executeStatement('DELETE FROM super_admin WHERE id = :id', ['id' => $this->adminId]);
        }
        parent::tearDown();
    }

    /** @return array{0: string} */
    private function createSuperAdmin(string $email, string $password, bool $enabled = true): array
    {
        $this->adminId = Uuid::v4()->toRfc4122();
        $totp = self::getContainer()->get(TotpService::class);
        $secret = $totp->generateSecret();
        $identity = new SuperAdmin($this->adminId, $email, '', $totp->encrypt($secret));
        $identity->setPasswordHash(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($identity, $password));
        $this->admin()->executeStatement(
            'INSERT INTO super_admin (id, email, password_hash, totp_secret, enabled, created_at) VALUES (:id, :email, :password, :secret, :enabled, NOW())',
            ['id' => $this->adminId, 'email' => $email, 'password' => $identity->getPassword(), 'secret' => $identity->getTotpSecret(), 'enabled' => $enabled],
            ['enabled' => ParameterType::BOOLEAN],
        );

        return [$secret];
    }

    private function authenticate(string $email, string $password, string $secret): void
    {
        $this->json('POST', '/api/admin/auth/password', ['email' => $email, 'password' => $password]);
        self::assertResponseIsSuccessful();
        $totp = self::getContainer()->get(TotpService::class);
        $this->json('POST', '/api/admin/auth/totp', ['code' => $totp->code($secret, time())]);
        self::assertResponseIsSuccessful();
    }

    /** @param array<string, mixed> $body */
    /** @param array<string, string> $server */
    private function json(string $method, string $uri, array $body, array $server = []): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->requestIp,
            ...$server,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function responseBody(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
