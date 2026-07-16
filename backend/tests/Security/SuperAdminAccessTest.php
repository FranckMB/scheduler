<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\SuperAdmin;
use App\Security\TotpService;
use App\Tests\VerifiesRegistration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

#[Group('phase1')]
#[Group('integration')]
final class SuperAdminAccessTest extends WebTestCase
{
    use VerifiesRegistration;

    private KernelBrowser $client;

    private string $adminId;

    private string $requestIp;

    public function testClubJwtCanNeverCrossTheAdminFirewall(): void
    {
        $token = $this->registerVerified('SAA1');
        $this->client->request('GET', '/api/admin/auth/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testPasswordAloneDoesNotAuthenticateAndTotpIsMandatory(): void
    {
        $this->createSuperAdmin('root@example.test', 'VeryStrongPassword!');
        $this->json('POST', '/api/admin/auth/password', ['email' => 'root@example.test', 'password' => 'VeryStrongPassword!']);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/admin/auth/me');
        self::assertResponseStatusCodeSame(401, 'password stage must not create an admin session');

        $this->json('POST', '/api/admin/auth/totp', ['code' => '000000']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testValidPasswordAndTotpCreateAnAdminOnlySessionWithoutTenant(): void
    {
        [$secret] = $this->createSuperAdmin('ops@example.test', 'VeryStrongPassword!');
        $this->json('POST', '/api/admin/auth/password', ['email' => 'ops@example.test', 'password' => 'VeryStrongPassword!']);
        self::assertResponseIsSuccessful();

        $totp = self::getContainer()->get(TotpService::class);
        $this->json('POST', '/api/admin/auth/totp', ['code' => $totp->code($secret, time())]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/admin/auth/me');
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('ops@example.test', $body['email']);

        self::assertSame('', (string) self::getContainer()->get(ManagerRegistry::class)->getConnection()->fetchOne('SELECT current_setting(\'app.club_id\', true)'));
        self::assertGreaterThanOrEqual(3, (int) $this->admin()->fetchOne('SELECT COUNT(*) FROM admin_audit_log WHERE super_admin_id = :id OR super_admin_id IS NULL', ['id' => $this->adminId]));
    }

    public function testDisabledAdminAndExpiredChallengeAreRejected(): void
    {
        $this->createSuperAdmin('disabled@example.test', 'VeryStrongPassword!', false);
        $this->json('POST', '/api/admin/auth/password', ['email' => 'disabled@example.test', 'password' => 'VeryStrongPassword!']);
        self::assertResponseStatusCodeSame(401);

        $this->admin()->executeStatement('UPDATE super_admin SET enabled = TRUE WHERE id = :id', ['id' => $this->adminId]);
        $this->json('POST', '/api/admin/auth/password', ['email' => 'disabled@example.test', 'password' => 'VeryStrongPassword!']);
        self::assertResponseIsSuccessful();
        $session = $this->client->getRequest()->getSession();
        $session->set('admin_pending_at', time() - 301);
        $session->save();

        $this->json('POST', '/api/admin/auth/totp', ['code' => '123456']);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('Authentication challenge expired.', $this->responseBody()['error']);
    }

    public function testDisablingAnAdminRevokesItsExistingSession(): void
    {
        $secret = $this->createSuperAdmin('revoked@example.test', 'VeryStrongPassword!')[0];
        $this->authenticate('revoked@example.test', 'VeryStrongPassword!', $secret);
        $this->admin()->executeStatement('UPDATE super_admin SET enabled = FALSE WHERE id = :id', ['id' => $this->adminId]);

        $this->client->request('GET', '/api/admin/auth/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testLogoutRequiresCsrfAndDestroysTheAdminSession(): void
    {
        $secret = $this->createSuperAdmin('logout@example.test', 'VeryStrongPassword!')[0];
        $csrfToken = $this->authenticate('logout@example.test', 'VeryStrongPassword!', $secret);

        $this->client->request('POST', '/api/admin/auth/logout');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/api/admin/auth/logout', [], [], ['HTTP_X_CSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/admin/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminAuthenticationIsRateLimitedPerIp(): void
    {
        $ip = '203.0.113.42';
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $this->json('POST', '/api/admin/auth/password', [
                'email' => 'missing@example.test',
                'password' => 'wrong-password',
            ], ['REMOTE_ADDR' => $ip]);
        }

        self::assertResponseStatusCodeSame(429);
    }

    public function testRuntimeRoleHasNoPrivilegeOnAdminIdentityOrAuditTables(): void
    {
        foreach (['super_admin', 'admin_audit_log'] as $table) {
            foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
                self::assertFalse((bool) $this->admin()->fetchOne(
                    'SELECT has_table_privilege(\'app_user\', :table, :privilege)',
                    ['table' => $table, 'privilege' => $privilege],
                ));
            }
        }
    }

    public function testCreateCommandCreatesAnMfaIdentityAndRejectsDuplicateEmail(): void
    {
        $email = 'command@example.test';
        $application = new Application(self::$kernel);
        $weakPassword = new CommandTester($application->find('app:superadmin:create'));
        $weakPassword->setInputs(['onlylowercase!']);
        self::assertSame(Command::INVALID, $weakPassword->execute(['email' => 'weak@example.test']));

        $tester = new CommandTester($application->find('app:superadmin:create'));
        $tester->setInputs(['A-command-password-123!', 'A-command-password-123!']);

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => $email]));
        self::assertStringContainsString('Provisioning URI:', $tester->getDisplay());
        $this->adminId = (string) $this->admin()->fetchOne('SELECT id FROM super_admin WHERE email = :email', ['email' => $email]);
        self::assertNotSame('', $this->adminId);

        $duplicate = new CommandTester($application->find('app:superadmin:create'));
        $duplicate->setInputs(['A-command-password-123!', 'A-command-password-123!']);
        self::assertSame(Command::FAILURE, $duplicate->execute(['email' => strtoupper($email)]));
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

    private function registerVerified(string $ara): string
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $email = $suffix . '@test.fr';
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $email,
            'password' => 'Password123!',
            'firstName' => 'Super',
            'lastName' => 'Admin isolation',
            'ara' => strtoupper($suffix),
            'club_name' => 'Club ' . $ara,
            'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);

        return $token;
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
