<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\AdminHealthService;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemPoolInterface;
use Redis;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\TransportInterface;

#[Group('phase1')]
final class AdminHealthServiceTest extends KernelTestCase
{
    public function testContainersReturnsElevenEntries(): void
    {
        // MAILER_DSN pointe mailpit (dev) → la sonde mailpit est listée.
        $httpClient = new MockHttpClient(fn (string $method, string $url): MockResponse => new MockResponse('', ['http_code' => 200]));
        $service = $this->buildService($httpClient);
        $health = $service->health();
        $containers = $health['containers'];

        self::assertCount(11, $containers);

        $keys = array_column($containers, 'key');
        self::assertContains('postgres', $keys);
        self::assertContains('redis', $keys);
        self::assertContains('nginx', $keys);
        self::assertContains('frontend', $keys);
        self::assertContains('engine', $keys);
        self::assertContains('mercure', $keys);
        self::assertContains('mailpit', $keys);
        self::assertContains('php-fpm', $keys);
        self::assertContains('messenger-worker', $keys);
        self::assertContains('cron-runner', $keys);
        self::assertContains('pdf-worker', $keys);
    }

    /**
     * P4-29 — la prod n'embarque PAS mailpit (MAILER_DSN = SMTP réel) : le sonder
     * afficherait un conteneur « down » permanent et un statut global degraded.
     * La sonde n'existe que si le déploiement fait réellement tourner mailpit.
     */
    public function testMailpitIsAbsentWhenTheDeploymentDoesNotRunIt(): void
    {
        $httpClient = new MockHttpClient(fn (string $method, string $url): MockResponse => new MockResponse('', ['http_code' => 200]));
        $service = $this->buildService($httpClient, null, 'redis://localhost:6379', 'smtp://smtp.example.com:587');
        $health = $service->health();

        $keys = array_column($health['containers'], 'key');
        self::assertNotContains('mailpit', $keys, 'aucune sonde mailpit hors dev');
        self::assertCount(10, $health['containers']);
        // Les autres conteneurs restent listés : on retire une sonde, pas la liste.
        self::assertContains('php-fpm', $keys);
        self::assertContains('pdf-worker', $keys);
    }

    public function testHeartbeatProbesReturnUnknownWhenCacheMisses(): void
    {
        $httpClient = new MockHttpClient(fn (string $method, string $url): MockResponse => new MockResponse('', ['http_code' => 200]));
        $service = $this->buildService($httpClient, new ArrayAdapter);
        $health = $service->health();

        $byKey = [];
        foreach ($health['containers'] as $entry) {
            $byKey[$entry['key']] = $entry;
        }

        foreach (['messenger-worker', 'cron-runner', 'pdf-worker'] as $key) {
            self::assertArrayHasKey($key, $byKey, "Container $key missing from health payload");
            self::assertSame('unknown', $byKey[$key]['status']);
            self::assertNull($byKey[$key]['lastHeartbeatAt']);
            self::assertNull($byKey[$key]['ageSeconds']);
        }
    }

    public function testHeartbeatProbesReturnUpWhenCacheHitAndRecent(): void
    {
        $httpClient = new MockHttpClient(fn (string $method, string $url): MockResponse => new MockResponse('', ['http_code' => 200]));
        $cache = new ArrayAdapter;

        // messenger + cron lisent le pool cache.app (écrits côté PHP) — seedés via le cache.
        // pdf-worker lit Redis EN BRUT (écrit par Node) : couvert par son propre test.
        foreach ([
            'admin_monitoring.messenger.heartbeat' => 30,
            'admin_monitoring.cron_runner.heartbeat' => 180,
        ] as $cacheKey => $ttl) {
            $item = $cache->getItem($cacheKey);
            $item->set(time())->expiresAfter($ttl);
            $cache->save($item);
        }

        $service = $this->buildService($httpClient, $cache);
        $health = $service->health();

        $byKey = [];
        foreach ($health['containers'] as $entry) {
            $byKey[$entry['key']] = $entry;
        }

        foreach (['messenger-worker', 'cron-runner'] as $key) {
            self::assertSame('up', $byKey[$key]['status'], "Container $key should be up with fresh heartbeat");
            self::assertIsString($byKey[$key]['lastHeartbeatAt']);
            self::assertIsInt($byKey[$key]['ageSeconds']);
            self::assertLessThanOrEqual(1, $byKey[$key]['ageSeconds']);
        }
    }

    public function testPdfWorkerHeartbeatIsReadRawFromRedisInSeconds(): void
    {
        // pdf-worker écrit HORS cache.app (clé brute, secondes) : le probe lit Redis direct.
        // Reproduit l'écriture Node → probe = up ; clé absente → unknown.
        $redisUrl = 'redis://redis:6379';
        $redis = new Redis;
        $redis->connect('redis', 6379, 1.0);
        $key = 'admin_monitoring.pdf_worker.heartbeat';

        $redis->set($key, (string) time(), ['EX' => 120]);
        $httpClient = new MockHttpClient(fn (string $method, string $url): MockResponse => new MockResponse('', ['http_code' => 200]));
        $service = $this->buildService($httpClient, new ArrayAdapter, $redisUrl);
        $pdf = $this->containerByKey($service->health(), 'pdf-worker');
        self::assertSame('up', $pdf['status'], 'pdf-worker up avec un heartbeat brut frais');
        self::assertLessThanOrEqual(1, $pdf['ageSeconds']);

        $redis->del($key);
        $pdf = $this->containerByKey($this->buildService($httpClient, new ArrayAdapter, $redisUrl)->health(), 'pdf-worker');
        self::assertSame('unknown', $pdf['status'], 'clé absente → unknown');
        $redis->close();
    }

    public function testExternalDependenciesReturnsThreeEntries(): void
    {
        $httpClient = new MockHttpClient(fn (string $method, string $url): MockResponse => new MockResponse('', ['http_code' => 200]));
        $service = $this->buildService($httpClient);
        $health = $service->health();
        $deps = $health['externalDependencies'];

        self::assertCount(3, $deps);

        self::assertSame('ffbb-meilisearch', $deps[0]['key']);
        self::assertSame('ods', $deps[1]['key']);
        self::assertSame('etalab', $deps[2]['key']);
    }

    /**
     * @param array<string, mixed> $health
     *
     * @return array<string, mixed>
     */
    private function containerByKey(array $health, string $key): array
    {
        foreach ($health['containers'] as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }
        self::fail("Container $key absent");
    }

    private function buildService(?MockHttpClient $httpClient = null, ?CacheItemPoolInterface $cache = null, string $redisUrl = 'redis://localhost:6379', string $mailerDsn = 'smtp://mailpit:1025'): AdminHealthService
    {
        return new AdminHealthService(
            $this->createMock(ManagerRegistry::class),
            $httpClient ?? $this->createMock(MockHttpClient::class),
            $this->createMock(TransportInterface::class),
            $this->createMock(TransportInterface::class),
            $cache ?? $this->createMock(CacheItemPoolInterface::class),
            $redisUrl,
            '',
            $mailerDsn,
        );
    }
}
