<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\SuperAdmin;
use App\Security\TotpService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

#[Group('phase1')]
#[Group('integration')]
final class AdminAuditLogControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private string $adminId;

    private string $requestIp;

    /** @var list<string> */
    private array $auditLogIds = [];

    public function testEmptyTableReturns200WithEmptyItems(): void
    {
        [$secret] = $this->createSuperAdmin('empty@example.test', 'VeryStrongPassword!');
        $this->authenticate('empty@example.test', 'VeryStrongPassword!', $secret);
        $this->clearAdminAuditLog();

        $this->client->request('GET', '/api/admin/audit-log');
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertSame([], $body['items']);
        self::assertSame(1, $body['pagination']['page']);
        self::assertSame(50, $body['pagination']['limit']);
        self::assertSame(0, $body['pagination']['total']);
        self::assertSame(0, $body['pagination']['pages']);
    }

    public function testOneAuditEntryReturnsCorrectFields(): void
    {
        [$secret] = $this->createSuperAdmin('one@example.test', 'VeryStrongPassword!');
        $this->authenticate('one@example.test', 'VeryStrongPassword!', $secret);
        $this->clearAdminAuditLog();

        $this->seedAuditLog($this->adminId, 'app_adminauth_me', 200, ['method' => 'GET']);

        $this->client->request('GET', '/api/admin/audit-log');
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertCount(1, $body['items']);
        $item = $body['items'][0];
        self::assertSame($this->adminId, $item['actorId']);
        self::assertSame('one@example.test', $item['actorEmail']);
        self::assertSame('app_adminauth_me', $item['route']);
        self::assertSame(['method' => 'GET'], $item['context']);
        self::assertSame(200, $item['status']);
        self::assertIsString($item['id']);
        self::assertIsString($item['createdAt']);
    }

    public function testPaginationPageTwoWithLimitOne(): void
    {
        [$secret] = $this->createSuperAdmin('page@example.test', 'VeryStrongPassword!');
        $this->authenticate('page@example.test', 'VeryStrongPassword!', $secret);
        $this->clearAllAdminAuditLog();

        $id1 = $this->seedAuditLog($this->adminId, 'route_a', 200, [], occurredAt: '2026-01-01 00:00:00+00');
        $id2 = $this->seedAuditLog($this->adminId, 'route_b', 201, [], occurredAt: '2026-01-02 00:00:00+00');

        $this->client->request('GET', '/api/admin/audit-log?page=2&limit=1');
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertCount(1, $body['items']);
        self::assertSame($id1, $body['items'][0]['id']);
        self::assertSame(2, $body['pagination']['page']);
        self::assertSame(1, $body['pagination']['limit']);
        self::assertSame(2, $body['pagination']['total']);
        self::assertSame(2, $body['pagination']['pages']);
    }

    public function testFilterByActorReducesResultSet(): void
    {
        [$secretA] = $this->createSuperAdmin('actor-a@example.test', 'VeryStrongPassword!');
        $adminIdA = $this->adminId;
        [$secretB] = $this->createSuperAdmin('actor-b@example.test', 'VeryStrongPassword!');
        $adminIdB = $this->adminId;

        $this->authenticate('actor-a@example.test', 'VeryStrongPassword!', $secretA);
        $this->clearAllAdminAuditLog();
        $this->seedAuditLog($adminIdA, 'route_a', 200, []);
        // Seed an entry for B directly (no session switch needed)
        $this->seedAuditLog($adminIdB, 'route_b', 201, []);

        // Filter by actor A
        $this->client->request('GET', '/api/admin/audit-log?actor=' . $adminIdA);
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertCount(1, $body['items']);
        self::assertSame('route_a', $body['items'][0]['route']);
        self::assertSame($adminIdA, $body['items'][0]['actorId']);
    }

    public function testLimitGreaterThan200IsClampedTo200(): void
    {
        [$secret] = $this->createSuperAdmin('limit@example.test', 'VeryStrongPassword!');
        $this->authenticate('limit@example.test', 'VeryStrongPassword!', $secret);
        $this->clearAdminAuditLog();

        $this->client->request('GET', '/api/admin/audit-log?limit=500');
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertSame(200, $body['pagination']['limit']);
    }

    public function testInvalidActorUuidReturns400(): void
    {
        [$secret] = $this->createSuperAdmin('bad-actor@example.test', 'VeryStrongPassword!');
        $this->authenticate('bad-actor@example.test', 'VeryStrongPassword!', $secret);
        $this->clearAdminAuditLog();

        $this->client->request('GET', '/api/admin/audit-log?actor=not-a-uuid');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('Invalid actor UUID.', $this->responseBody()['error']);
    }

    public function testInvalidSinceDateReturns400(): void
    {
        [$secret] = $this->createSuperAdmin('bad-since@example.test', 'VeryStrongPassword!');
        $this->authenticate('bad-since@example.test', 'VeryStrongPassword!', $secret);
        $this->clearAdminAuditLog();

        $this->client->request('GET', '/api/admin/audit-log?since=2024-13-01');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('Invalid since date. Expected ISO date (YYYY-MM-DD).', $this->responseBody()['error']);
    }

    public function testNegativePageReturns400(): void
    {
        [$secret] = $this->createSuperAdmin('bad-page@example.test', 'VeryStrongPassword!');
        $this->authenticate('bad-page@example.test', 'VeryStrongPassword!', $secret);
        $this->clearAdminAuditLog();

        $this->client->request('GET', '/api/admin/audit-log?page=-1');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('Invalid page: must be a positive integer.', $this->responseBody()['error']);
    }

    public function testRouteFilterUsesLikeSubstring(): void
    {
        [$secret] = $this->createSuperAdmin('route@example.test', 'VeryStrongPassword!');
        $this->authenticate('route@example.test', 'VeryStrongPassword!', $secret);
        $this->clearAdminAuditLog();

        $this->seedAuditLog($this->adminId, 'app_adminauth_me', 200, []);
        $this->seedAuditLog($this->adminId, 'app_adminhealth_health', 200, []);

        $this->client->request('GET', '/api/admin/audit-log?route=adminauth');
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertCount(1, $body['items']);
        self::assertSame('app_adminauth_me', $body['items'][0]['route']);
    }

    public function testSinceFilterExcludesOlderEntries(): void
    {
        [$secret] = $this->createSuperAdmin('since@example.test', 'VeryStrongPassword!');
        $this->authenticate('since@example.test', 'VeryStrongPassword!', $secret);
        $this->clearAdminAuditLog();

        $this->seedAuditLog($this->adminId, 'old_route', 200, [], occurredAt: '2024-01-01 00:00:00+00');
        $this->seedAuditLog($this->adminId, 'new_route', 200, [], occurredAt: '2026-01-15 00:00:00+00');

        $this->client->request('GET', '/api/admin/audit-log?since=2026-01-01');
        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertCount(1, $body['items']);
        self::assertSame('new_route', $body['items'][0]['route']);
    }

    public function testUnauthenticatedRequestReturns401(): void
    {
        $this->client->request('GET', '/api/admin/audit-log');
        self::assertResponseStatusCodeSame(401);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->requestIp = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }

    protected function tearDown(): void
    {
        if ([] !== $this->auditLogIds) {
            $this->admin()->executeStatement(
                'DELETE FROM admin_audit_log WHERE id IN (:ids)',
                ['ids' => $this->auditLogIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }
        if (isset($this->adminId)) {
            $this->admin()->executeStatement('DELETE FROM admin_audit_log WHERE super_admin_id = :id OR super_admin_id IS NULL', ['id' => $this->adminId]);
            $this->admin()->executeStatement('DELETE FROM super_admin WHERE id = :id', ['id' => $this->adminId]);
        }
        parent::tearDown();
    }

    /** @return array{0: string} */
    private function createSuperAdmin(string $email, string $password): array
    {
        $this->adminId = Uuid::v4()->toRfc4122();
        $totp = self::getContainer()->get(TotpService::class);
        $secret = $totp->generateSecret();
        $identity = new SuperAdmin($this->adminId, $email, '', $totp->encrypt($secret));
        $identity->setPasswordHash(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($identity, $password));
        $this->admin()->executeStatement(
            'INSERT INTO super_admin (id, email, password_hash, totp_secret, enabled, created_at) VALUES (:id, :email, :password, :secret, :enabled, NOW())',
            ['id' => $this->adminId, 'email' => $email, 'password' => $identity->getPassword(), 'secret' => $identity->getTotpSecret(), 'enabled' => true],
            ['enabled' => ParameterType::BOOLEAN],
        );

        return [$secret];
    }

    private function authenticate(string $email, string $password, string $secret): string
    {
        $this->json('POST', '/api/admin/auth/password', ['email' => $email, 'password' => $password]);
        self::assertResponseIsSuccessful();
        $totp = self::getContainer()->get(TotpService::class);
        $this->json('POST', '/api/admin/auth/totp', ['code' => $totp->code($secret, time())]);
        self::assertResponseIsSuccessful();
        $csrfToken = $this->responseBody()['csrfToken'] ?? null;
        self::assertIsString($csrfToken);

        return $csrfToken;
    }

    private function clearAdminAuditLog(): void
    {
        $this->admin()->executeStatement(
            'DELETE FROM admin_audit_log WHERE super_admin_id = :id OR super_admin_id IS NULL',
            ['id' => $this->adminId],
        );
    }

    private function clearAllAdminAuditLog(): void
    {
        $this->admin()->executeStatement('DELETE FROM admin_audit_log');
    }

    /**
     * @param array<string, mixed> $details
     */
    private function seedAuditLog(?string $superAdminId, string $route, int $statusCode, array $details, string $occurredAt = 'NOW()'): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->auditLogIds[] = $id;

        if ('NOW()' === $occurredAt) {
            $this->admin()->executeStatement(
                'INSERT INTO admin_audit_log (id, occurred_at, super_admin_id, action, route, status_code, details) VALUES (:id, NOW(), :actor, :action, :route, :status, :details)',
                [
                    'id' => $id,
                    'actor' => $superAdminId,
                    'action' => 'admin.http_access',
                    'route' => $route,
                    'status' => $statusCode,
                    'details' => json_encode($details, \JSON_THROW_ON_ERROR),
                ],
            );
        } else {
            $this->admin()->executeStatement(
                'INSERT INTO admin_audit_log (id, occurred_at, super_admin_id, action, route, status_code, details) VALUES (:id, :occurred_at, :actor, :action, :route, :status, :details)',
                [
                    'id' => $id,
                    'occurred_at' => $occurredAt,
                    'actor' => $superAdminId,
                    'action' => 'admin.http_access',
                    'route' => $route,
                    'status' => $statusCode,
                    'details' => json_encode($details, \JSON_THROW_ON_ERROR),
                ],
            );
        }

        return $id;
    }

    /** @param array<string, mixed> $body */
    private function json(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->requestIp,
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
