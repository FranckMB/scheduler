<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\AdminHealthService;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * External-dependency probes in AdminHealthService : 3 public services (FFBB
 * Meilisearch, ODS, Etalab), GET-only, 2 s timeout, measured latency.
 */
#[Group('phase1')]
final class AdminHealthServiceExternalDepsTest extends KernelTestCase
{
    public function testAllProbesReturnUpWithLatency(): void
    {
        $httpClient = new MockHttpClient(fn (string $method, string $url): MockResponse => new MockResponse('', ['http_code' => 200]));

        $service = $this->buildService($httpClient);
        $health = $service->health();
        $deps = $health['externalDependencies'];

        self::assertCount(3, $deps);
        self::assertSame('ffbb-meilisearch', $deps[0]['key']);
        self::assertSame('ods', $deps[1]['key']);
        self::assertSame('etalab', $deps[2]['key']);

        foreach ($deps as $dep) {
            self::assertArrayHasKey('name', $dep);
            self::assertArrayHasKey('status', $dep);
            self::assertArrayHasKey('latencyMs', $dep);
            self::assertSame('up', $dep['status']);
            self::assertGreaterThanOrEqual(0, $dep['latencyMs']);
        }
    }

    public function testTimeoutReturnsDownWithLatencyAroundTwoSeconds(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, 'meilisearch-prod.ffbb.app')) {
                usleep(2000000);
                throw new TransportException('Timeout');
            }

            return new MockResponse('', ['http_code' => 200]);
        });

        $service = $this->buildService($httpClient);
        $health = $service->health();
        $deps = $health['externalDependencies'];

        self::assertCount(3, $deps);

        self::assertSame('ffbb-meilisearch', $deps[0]['key']);
        self::assertSame('down', $deps[0]['status']);
        self::assertGreaterThanOrEqual(2000, $deps[0]['latencyMs']);
        self::assertLessThanOrEqual(2500, $deps[0]['latencyMs']);

        self::assertSame('up', $deps[1]['status']);
        self::assertSame('up', $deps[2]['status']);
    }

    private function buildService(MockHttpClient $httpClient): AdminHealthService
    {
        return new AdminHealthService(
            $this->createMock(ManagerRegistry::class),
            $httpClient,
            $this->createMock(TransportInterface::class),
            $this->createMock(TransportInterface::class),
            $this->createMock(CacheItemPoolInterface::class),
            'redis://localhost:6379',
            '',
        );
    }
}
