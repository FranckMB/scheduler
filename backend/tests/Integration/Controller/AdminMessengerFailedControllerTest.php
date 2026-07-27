<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\SuperAdmin;
use App\Message\GenerateScheduleMessage;
use App\Security\TotpService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Redis;
use ReflectionClass;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection as RedisConnection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

#[Group('phase1')]
#[Group('integration')]
final class AdminMessengerFailedControllerTest extends WebTestCase
{
    private string $adminId;

    private string $requestIp;

    private KernelBrowser $client;

    /** @var list<string> */
    private array $redisMessageIds = [];

    public function testNoFailedMessagesReturnsEmptyItems(): void
    {
        $this->createAndAuthenticateAdmin();

        $this->client->request('GET', '/api/admin/messenger/failed?page=1&limit=10');

        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertArrayHasKey('items', $body);
        self::assertSame([], $body['items']);
        // La réponse DOIT porter `pagination` (le front la déstructure — sinon crash).
        self::assertArrayHasKey('pagination', $body);
        foreach (['page', 'limit', 'total', 'pages'] as $key) {
            self::assertArrayHasKey($key, $body['pagination']);
        }
        self::assertSame(0, $body['pagination']['total']);
    }

    public function testOneFailedMessageReturnsCorrectFieldsAndNoBody(): void
    {
        $this->createAndAuthenticateAdmin();

        $messageId = $this->sendFailedMessage(
            new GenerateScheduleMessage(Uuid::v4()->toRfc4122(), Uuid::v4()->toRfc4122()),
            failedAt: new DateTimeImmutable('2026-07-20 14:30:00', new DateTimeZone('UTC')),
            errorMessage: 'Engine timeout after 30s',
        );
        $this->redisMessageIds[] = $messageId;

        $this->client->request('GET', '/api/admin/messenger/failed?page=1&limit=10');

        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertArrayHasKey('items', $body);
        self::assertCount(1, $body['items']);
        // total DOIT refléter le rendu (pas de page fantôme) — revue #296 round 2.
        self::assertSame(1, $body['pagination']['total']);
        self::assertSame(1, $body['pagination']['pages']);

        $item = $body['items'][0];
        self::assertArrayHasKey('id', $item);
        self::assertArrayHasKey('class', $item);
        self::assertArrayHasKey('failedAt', $item);
        self::assertArrayHasKey('lastErrorMessage', $item);
        self::assertSame(GenerateScheduleMessage::class, $item['class']);
        self::assertSame('2026-07-20T14:30:00+00:00', $item['failedAt']);
        self::assertSame('Engine timeout after 30s', $item['lastErrorMessage']);
        self::assertArrayNotHasKey('body', $item, 'Message body must NEVER be exposed');
    }

    public function testPaginationPageTwoReturnsCorrectSlice(): void
    {
        $this->createAndAuthenticateAdmin();

        // Seed 3 messages so page 2 with limit 2 returns the 3rd
        for ($i = 0; $i < 3; ++$i) {
            $messageId = $this->sendFailedMessage(
                new GenerateScheduleMessage(Uuid::v4()->toRfc4122(), Uuid::v4()->toRfc4122()),
                failedAt: new DateTimeImmutable('2026-07-20 14:30:00', new DateTimeZone('UTC')),
                errorMessage: 'Error ' . $i,
            );
            $this->redisMessageIds[] = $messageId;
        }

        $this->client->request('GET', '/api/admin/messenger/failed?page=2&limit=2');

        self::assertResponseIsSuccessful();
        $body = $this->responseBody();
        self::assertArrayHasKey('items', $body);
        self::assertCount(1, $body['items']);
        self::assertSame('Error 2', $body['items'][0]['lastErrorMessage']);
    }

    protected function setUp(): void
    {
        $this->requestIp = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $this->client = self::createClient();
        $this->cleanupAllFailedMessages();
    }

    protected function tearDown(): void
    {
        if ([] !== $this->redisMessageIds) {
            $this->cleanupRedisMessages();
        }
        if (isset($this->adminId)) {
            $this->admin()->executeStatement('DELETE FROM admin_audit_log WHERE super_admin_id = :id OR super_admin_id IS NULL', ['id' => $this->adminId]);
            $this->admin()->executeStatement('DELETE FROM super_admin WHERE id = :id', ['id' => $this->adminId]);
        }
        parent::tearDown();
    }

    private function createAndAuthenticateAdmin(): void
    {
        $email = 'messenger-failed@example.test';
        $password = 'VeryStrongPassword!';
        $secret = $this->createSuperAdmin($email, $password);

        $this->client->request('POST', '/api/admin/auth/password', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->requestIp,
        ], json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $totp = self::getContainer()->get(TotpService::class);
        $this->client->request('POST', '/api/admin/auth/totp', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->requestIp,
        ], json_encode(['code' => $totp->code($secret, time())], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    /** @return array{0: string} */
    private function createSuperAdmin(string $email, string $password): string
    {
        $this->adminId = Uuid::v4()->toRfc4122();
        $totp = self::getContainer()->get(TotpService::class);
        $secret = $totp->generateSecret();
        $identity = new SuperAdmin($this->adminId, $email, '', $totp->encrypt($secret));
        $identity->setPasswordHash(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($identity, $password));
        $this->admin()->executeStatement(
            'INSERT INTO super_admin (id, email, password_hash, totp_secret, enabled, created_at) VALUES (:id, :email, :password, :secret, TRUE, NOW())',
            ['id' => $this->adminId, 'email' => $email, 'password' => $identity->getPassword(), 'secret' => $identity->getTotpSecret()],
        );

        return $secret;
    }

    private function sendFailedMessage(GenerateScheduleMessage $message, DateTimeImmutable $failedAt, string $errorMessage): string
    {
        $dsn = $_ENV['MESSENGER_FAILURE_TRANSPORT_DSN'] ?? 'redis://redis:6379/messages/failed';
        $connection = RedisConnection::fromDsn($dsn, ['auto_setup' => false]);
        $transport = new RedisTransport($connection);

        $envelope = new Envelope($message, [
            new RedeliveryStamp(3, $failedAt),
            ErrorDetailsStamp::create(new RuntimeException($errorMessage)),
        ]);

        $sent = $transport->send($envelope);
        /** @var TransportMessageIdStamp|null $stamp */
        $stamp = $sent->last(TransportMessageIdStamp::class);

        return (string) ($stamp?->getId() ?? Uuid::v4()->toRfc4122());
    }

    private function cleanupAllFailedMessages(): void
    {
        $dsn = 'redis://redis:6379/messages/failed';
        $connection = RedisConnection::fromDsn($dsn, ['auto_setup' => false]);
        $redis = $this->getRedisFromConnection($connection);
        $stream = $this->resolveStreamName($dsn);

        while (true) {
            $range = $redis->xRange($stream, '-', '+', 100);
            if (false === $range || [] === $range) {
                break;
            }
            $ids = array_keys($range);
            if ([] === $ids) {
                break;
            }
            try {
                $redis->xDel($stream, $ids);
            } catch (Throwable) {
                break;
            }
        }
    }

    private function cleanupRedisMessages(): void
    {
        $dsn = 'redis://redis:6379/messages/failed';
        $connection = RedisConnection::fromDsn($dsn, ['auto_setup' => false]);
        $redis = $this->getRedisFromConnection($connection);
        $stream = $this->resolveStreamName($dsn);

        foreach ($this->redisMessageIds as $id) {
            try {
                $redis->xDel($stream, [$id]);
            } catch (Throwable) {
                // Best-effort cleanup; ignore failures
            }
        }
    }

    private function getRedisFromConnection(RedisConnection $connection): Redis
    {
        $ref = new ReflectionClass($connection);
        $method = $ref->getMethod('getRedis');
        $method->setAccessible(true);
        $redis = $method->invoke($connection);
        \assert($redis instanceof Redis);

        return $redis;
    }

    private function resolveStreamName(string $dsn): string
    {
        $parts = parse_url($dsn);
        if (!\is_array($parts)) {
            return 'messages';
        }
        $path = $parts['path'] ?? '';
        $pathParts = explode('/', trim($path, '/'));

        return $pathParts[0] ?? 'messages';
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
