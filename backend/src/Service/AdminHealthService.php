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
        // Mailpit is a DEV catch-all: the prod stack ships no such container
        // and MAILER_DSN points at a real SMTP transport. Probing it there
        // would red the board (and the global status) forever — the DSN tells
        // us whether the container is supposed to exist at all.
        #[Autowire('%env(default::MAILER_DSN)%')]
        private string $mailerDsn = '',
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
        $externalDependencies = $this->externalDeps();
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
            'externalDependencies' => $externalDependencies,
            // Réutilise les 4 sondes déjà faites (db/redis/engine/mercure) — pas de re-probe.
            'containers' => $this->containers($database, $redis, $engine, $mercure),
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
    private function http(string $url, bool $requireSuccess, float $timeout = 1.0, float $maxDuration = 1.5): array
    {
        $startedAt = hrtime(true);
        try {
            $statusCode = $this->httpClient->request('GET', $url, ['timeout' => $timeout, 'max_duration' => $maxDuration])->getStatusCode();
            $up = $requireSuccess ? 200 === $statusCode : $statusCode < 500;

            return ['status' => $up ? 'up' : 'down', 'latencyMs' => $this->elapsed($startedAt)];
        } catch (Throwable) {
            return ['status' => 'down', 'latencyMs' => $this->elapsed($startedAt)];
        }
    }

    /** @return array<int, array{key: string, name: string, status: string, latencyMs: int}> */
    private function externalDeps(): array
    {
        $deps = [
            ['key' => 'ffbb-meilisearch', 'name' => 'FFBB Meilisearch', 'url' => 'https://meilisearch-prod.ffbb.app', 'requireSuccess' => false],
            ['key' => 'ods', 'name' => 'OpenDataSoft (Éducation nationale)', 'url' => 'https://data.education.gouv.fr', 'requireSuccess' => true],
            ['key' => 'etalab', 'name' => 'Etalab Calendrier', 'url' => 'https://calendrier.api.gouv.fr', 'requireSuccess' => true],
        ];

        $results = [];
        foreach ($deps as $dep) {
            $probe = $this->http($dep['url'], $dep['requireSuccess'], 2.0, 2.5);
            $results[] = [
                'key' => $dep['key'],
                'name' => $dep['name'],
                'status' => $probe['status'],
                'latencyMs' => $probe['latencyMs'],
            ];
        }

        return $results;
    }

    /**
     * @param array{status: string, latencyMs: int} $database
     * @param array{status: string, latencyMs: int} $redis
     * @param array{status: string, latencyMs: int} $engine
     * @param array{status: string, latencyMs: int} $mercure
     *
     * @return list<array{key: string, name: string, status: string, lastHeartbeatAt?: ?string, ageSeconds?: ?int, latencyMs?: int}>
     */
    private function containers(array $database, array $redis, array $engine, array $mercure): array
    {
        $nginx = $this->http('http://nginx/api/health', true, 1.0, 1.5);
        $frontend = $this->http('http://frontend/health', false, 1.0, 1.5);
        $mailpitExpected = str_contains($this->mailerDsn, 'mailpit');
        $mailpit = $mailpitExpected ? $this->http('http://mailpit:8025/', false, 1.0, 1.5) : null;
        $messengerWorker = $this->heartbeatProbe('admin_monitoring.messenger.heartbeat', 30);
        $cronRunner = $this->heartbeatProbe('admin_monitoring.cron_runner.heartbeat', 180);
        // pdf-worker écrit son heartbeat depuis NODE (clé RAW, secondes) — hors du pool
        // cache.app (qui namespace+sérialise) : lecture Redis brute dédiée.
        $pdfWorker = $this->rawHeartbeatProbe('admin_monitoring.pdf_worker.heartbeat', 60);

        $containers = [
            ['key' => 'postgres', 'name' => 'PostgreSQL', 'status' => $database['status'], 'latencyMs' => $database['latencyMs']],
            ['key' => 'redis', 'name' => 'Redis', 'status' => $redis['status'], 'latencyMs' => $redis['latencyMs']],
            ['key' => 'nginx', 'name' => 'Nginx', 'status' => $nginx['status'], 'latencyMs' => $nginx['latencyMs']],
            ['key' => 'frontend', 'name' => 'Frontend', 'status' => $frontend['status'], 'latencyMs' => $frontend['latencyMs']],
            ['key' => 'engine', 'name' => 'Engine', 'status' => $engine['status'], 'latencyMs' => $engine['latencyMs']],
            ['key' => 'mercure', 'name' => 'Mercure', 'status' => $mercure['status'], 'latencyMs' => $mercure['latencyMs']],
        ];

        // Listed only when the deployment actually runs it (dev catch-all).
        if (null !== $mailpit) {
            $containers[] = ['key' => 'mailpit', 'name' => 'Mailpit', 'status' => $mailpit['status'], 'latencyMs' => $mailpit['latencyMs']];
        }

        return [
            ...$containers,
            ['key' => 'php-fpm', 'name' => 'PHP-FPM', 'status' => 'up', 'latencyMs' => 0],
            ['key' => 'messenger-worker', 'name' => 'Messenger Worker', 'status' => $messengerWorker['status'], 'lastHeartbeatAt' => $messengerWorker['lastHeartbeatAt'], 'ageSeconds' => $messengerWorker['ageSeconds']],
            ['key' => 'cron-runner', 'name' => 'Cron Runner', 'status' => $cronRunner['status'], 'lastHeartbeatAt' => $cronRunner['lastHeartbeatAt'], 'ageSeconds' => $cronRunner['ageSeconds']],
            ['key' => 'pdf-worker', 'name' => 'PDF Worker', 'status' => $pdfWorker['status'], 'lastHeartbeatAt' => $pdfWorker['lastHeartbeatAt'], 'ageSeconds' => $pdfWorker['ageSeconds']],
        ];
    }

    /**
     * @return array{status: string, lastHeartbeatAt: ?string, ageSeconds: ?int}
     */
    private function heartbeatProbe(string $cacheKey, int $maxAgeSeconds): array
    {
        try {
            $item = $this->cache->getItem($cacheKey);
            $timestamp = $item->isHit() && \is_int($item->get()) ? $item->get() : null;

            return $this->heartbeatResult($timestamp, $maxAgeSeconds);
        } catch (Throwable) {
            return ['status' => 'unknown', 'lastHeartbeatAt' => null, 'ageSeconds' => null];
        }
    }

    /**
     * Heartbeat écrit HORS du pool cache.app — clé Redis BRUTE, valeur = timestamp UNIX en
     * SECONDES (le pdf-worker Node ne peut pas produire la clé namespacée/sérialisée de
     * Symfony). Lecture Redis directe, mêmes règles d'âge que `heartbeatProbe`.
     *
     * @return array{status: string, lastHeartbeatAt: ?string, ageSeconds: ?int}
     */
    private function rawHeartbeatProbe(string $key, int $maxAgeSeconds): array
    {
        $redis = null;
        $connected = false;
        try {
            $parts = parse_url($this->redisUrl);
            if (!\is_array($parts) || !isset($parts['host'])) {
                return ['status' => 'unknown', 'lastHeartbeatAt' => null, 'ageSeconds' => null];
            }
            $redis = new Redis;
            $redis->connect($parts['host'], (int) ($parts['port'] ?? 6379), 1.0);
            $connected = true;
            $redis->setOption(Redis::OPT_READ_TIMEOUT, 1.0);
            if (isset($parts['pass'])) {
                $redis->auth(isset($parts['user']) && '' !== $parts['user'] ? [$parts['user'], $parts['pass']] : $parts['pass']);
            }
            if (isset($parts['path']) && ctype_digit($database = ltrim($parts['path'], '/'))) {
                $redis->select((int) $database);
            }
            $raw = $redis->get($key);
            $timestamp = \is_string($raw) && ctype_digit($raw) ? (int) $raw : null;

            return $this->heartbeatResult($timestamp, $maxAgeSeconds);
        } catch (Throwable) {
            return ['status' => 'unknown', 'lastHeartbeatAt' => null, 'ageSeconds' => null];
        } finally {
            if ($connected) {
                try {
                    $redis?->close();
                } catch (Throwable) {
                    // résultat déjà connu ; la fermeture ne doit jamais casser l'endpoint.
                }
            }
        }
    }

    /**
     * Mappe un timestamp de heartbeat (secondes UNIX, ou null si absent) en état. « down »
     * n'est atteignable que si le TTL de la clé DÉPASSE `maxAge` (sinon la clé disparaît pile
     * à maxAge → on saute up→unknown). Absent → 'unknown'.
     *
     * @return array{status: string, lastHeartbeatAt: ?string, ageSeconds: ?int}
     */
    private function heartbeatResult(?int $timestamp, int $maxAgeSeconds): array
    {
        if (null === $timestamp) {
            return ['status' => 'unknown', 'lastHeartbeatAt' => null, 'ageSeconds' => null];
        }
        $age = max(0, time() - $timestamp);

        return [
            'status' => $age <= $maxAgeSeconds ? 'up' : 'down',
            'lastHeartbeatAt' => (new DateTimeImmutable)->setTimestamp($timestamp)->format(DateTimeInterface::ATOM),
            'ageSeconds' => $age,
        ];
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
