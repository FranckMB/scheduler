<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\AdminHealthService;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemPoolInterface;
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

        // Pre-seed the three heartbeat keys with the current timestamp so the probe
        // reads an "up" status with a fresh age.
        foreach ([
            'admin_monitoring.messenger.heartbeat' => 30,
            'admin_monitoring.cron_runner.heartbeat' => 180,
            'admin_monitoring.pdf_worker.heartbeat' => 60,
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

        foreach (['messenger-worker', 'cron-runner', 'pdf-worker'] as $key) {
            self::assertSame('up', $byKey[$key]['status'], "Container $key should be up with fresh heartbeat");
            self::assertIsString($byKey[$key]['lastHeartbeatAt']);
            self::assertIsInt($byKey[$key]['ageSeconds']);
            self::assertLessThanOrEqual(1, $byKey[$key]['ageSeconds']);
        }
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

    private function buildService(?MockHttpClient $httpClient = null, ?CacheItemPoolInterface $cache = null): AdminHealthService
    {
        return new AdminHealthService(
            $this->createMock(ManagerRegistry::class),
            $httpClient ?? $this->createMock(MockHttpClient::class),
            $this->createMock(TransportInterface::class),
            $this->createMock(TransportInterface::class),
            $cache ?? $this->createMock(CacheItemPoolInterface::class),
            'redis://localhost:6379',
            '',
        );
    }
}
