<?php

declare(strict_types=1);

namespace App\Service;

use App\EventListener\MessengerHealthSubscriber;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Redis;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/** Bounded, read-only infrastructure probes for the super-admin console. */
final readonly class AdminHealthService
{
    private const ENGINE_HEALTH_URL = 'http://engine:8000/health';
    private const WORKER_MAX_AGE_SECONDS = 30;
    private const BACKLOG_WARNING_THRESHOLD = 100;

    public function __construct(
        private ManagerRegistry $registry,
        private HttpClientInterface $httpClient,
        #[Autowire(service: 'messenger.transport.async')]
        private TransportInterface $asyncTransport,
        #[Autowire(service: 'messenger.transport.failed')]
        private TransportInterface $failedTransport,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        #[Autowire('%env(REDIS_URL)%')]
        private string $redisUrl,
        #[Autowire('%env(default::MERCURE_URL)%')]
        private string $mercureUrl,
    ) {}

    /** @return array<string, mixed> */
    public function health(): array
    {
        $database = $this->database();
        $redis = $this->redis();
        $engine = $this->http(self::ENGINE_HEALTH_URL, true);
        $mercure = '' === $this->mercureUrl ? $this->unknownProbe() : $this->http($this->mercureUrl, false);
        $worker = $this->worker();
        $messenger = $this->messenger();
        $statuses = [$database['status'], $redis['status'], $engine['status'], $mercure['status'], $worker['status'], $messenger['status']];

        return [
            'status' => 6 === \count(array_filter($statuses, static fn (string $status): bool => 'up' === $status)) ? 'healthy' : 'degraded',
            'checkedAt' => (new DateTimeImmutable)->format(DateTimeInterface::ATOM),
            'services' => [
                'database' => $database,
                'redis' => $redis,
                'engine' => $engine,
                'worker' => $worker,
                'mercure' => $mercure,
            ],
            'messenger' => $messenger,
        ];
    }

    /** @return array{status: string, latencyMs: int} */
    private function database(): array
    {
        $startedAt = hrtime(true);
        try {
            $connection = $this->registry->getConnection('admin');
            \assert($connection instanceof Connection);
            $up = 1 === (int) $connection->fetchOne('SELECT 1');

            return ['status' => $up ? 'up' : 'down', 'latencyMs' => $this->elapsed($startedAt)];
        } catch (Throwable) {
            return ['status' => 'down', 'latencyMs' => $this->elapsed($startedAt)];
        }
    }

    /** @return array{status: string, latencyMs: int} */
    private function redis(): array
    {
        $startedAt = hrtime(true);
        $redis = null;
        $connected = false;
        try {
            $parts = parse_url($this->redisUrl);
            if (!\is_array($parts) || !isset($parts['host'])) {
                throw new RuntimeException('Invalid Redis URL.');
            }

            $redis = new Redis;
            $redis->connect($parts['host'], (int) ($parts['port'] ?? 6379), 1.0);
            $connected = true;
            $redis->setOption(Redis::OPT_READ_TIMEOUT, 1.0);
            if (isset($parts['pass'])) {
                $credentials = isset($parts['user']) && '' !== $parts['user'] ? [$parts['user'], $parts['pass']] : $parts['pass'];
                $redis->auth($credentials);
            }
            if (isset($parts['path']) && ctype_digit($database = ltrim($parts['path'], '/'))) {
                $redis->select((int) $database);
            }
            $redis->ping();

            return ['status' => 'up', 'latencyMs' => $this->elapsed($startedAt)];
        } catch (Throwable) {
            return ['status' => 'down', 'latencyMs' => $this->elapsed($startedAt)];
        } finally {
            if ($connected) {
                try {
                    $redis?->close();
                } catch (Throwable) {
                    // Probe result is already known; closing must never break the endpoint.
                }
            }
        }
    }

    /** @return array{status: string, latencyMs: int} */
    private function http(string $url, bool $requireSuccess): array
    {
        $startedAt = hrtime(true);
        try {
            $statusCode = $this->httpClient->request('GET', $url, ['timeout' => 1.0, 'max_duration' => 1.5])->getStatusCode();
            $up = $requireSuccess ? 200 === $statusCode : $statusCode < 500;

            return ['status' => $up ? 'up' : 'down', 'latencyMs' => $this->elapsed($startedAt)];
        } catch (Throwable) {
            return ['status' => 'down', 'latencyMs' => $this->elapsed($startedAt)];
        }
    }

    /** @return array{status: string, lastHeartbeatAt: ?string, ageSeconds: ?int} */
    private function worker(): array
    {
        try {
            $heartbeat = $this->cache->getItem(MessengerHealthSubscriber::HEARTBEAT_KEY);
            $timestamp = $heartbeat->isHit() && \is_int($heartbeat->get()) ? $heartbeat->get() : null;
            if (null === $timestamp) {
                return ['status' => 'unknown', 'lastHeartbeatAt' => null, 'ageSeconds' => null];
            }

            $age = max(0, time() - $timestamp);

            return [
                'status' => $age <= self::WORKER_MAX_AGE_SECONDS ? 'up' : 'down',
                'lastHeartbeatAt' => (new DateTimeImmutable)->setTimestamp($timestamp)->format(DateTimeInterface::ATOM),
                'ageSeconds' => $age,
            ];
        } catch (Throwable) {
            return ['status' => 'unknown', 'lastHeartbeatAt' => null, 'ageSeconds' => null];
        }
    }

    /** @return array{status: string, backlog: ?int, failed: ?int, retriesToday: ?int, backlogWarningThreshold: int} */
    private function messenger(): array
    {
        try {
            $backlog = $this->asyncTransport instanceof MessageCountAwareInterface ? $this->asyncTransport->getMessageCount() : null;
            $failed = $this->failedTransport instanceof MessageCountAwareInterface ? $this->failedTransport->getMessageCount() : null;
            $retryItem = $this->cache->getItem(MessengerHealthSubscriber::RETRIES_KEY_PREFIX . gmdate('Y-m-d'));
            $retries = $retryItem->isHit() && \is_int($retryItem->get()) ? $retryItem->get() : 0;
            $status = null === $backlog || null === $failed ? 'unknown' : (($failed > 0 || $backlog >= self::BACKLOG_WARNING_THRESHOLD) ? 'degraded' : 'up');

            return [
                'status' => $status,
                'backlog' => $backlog,
                'failed' => $failed,
                'retriesToday' => $retries,
                'backlogWarningThreshold' => self::BACKLOG_WARNING_THRESHOLD,
            ];
        } catch (Throwable) {
            return ['status' => 'unknown', 'backlog' => null, 'failed' => null, 'retriesToday' => null, 'backlogWarningThreshold' => self::BACKLOG_WARNING_THRESHOLD];
        }
    }

    /** @return array{status: string, latencyMs: int} */
    private function unknownProbe(): array
    {
        return ['status' => 'unknown', 'latencyMs' => 0];
    }

    private function elapsed(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
