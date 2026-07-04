<?php

declare(strict_types=1);

namespace App\Service;

use Redis;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ClubGenerationLock
{
    private const KEY_PREFIX = 'schedule_generation:club:';

    public function __construct(
        #[Autowire('%env(REDIS_URL)%')]
        private readonly string $redisUrl,
    ) {}

    public function acquire(string $clubId, int $ttlSeconds): ?string
    {
        $redis = $this->connect();
        $token = bin2hex(random_bytes(16));
        $ttlSeconds = max(1, $ttlSeconds);

        $acquired = $redis->set(self::KEY_PREFIX . $clubId, $token, ['nx', 'ex' => $ttlSeconds]);

        return $acquired ? $token : null;
    }

    public function release(string $clubId, string $token): void
    {
        $redis = $this->connect();
        $key = self::KEY_PREFIX . $clubId;

        // Atomic compare-and-delete (BCK-02): only the holder whose token matches
        // may delete the key, and the check + delete run as one Redis operation.
        // The previous GET-then-DEL had a race — if the key's TTL expired and
        // another worker re-acquired the lock between the two calls, this DEL
        // would delete that other worker's lock.
        $redis->eval(
            'if redis.call(\'get\', KEYS[1]) == ARGV[1] then return redis.call(\'del\', KEYS[1]) else return 0 end',
            [$key, $token],
            1,
        );
    }

    private function connect(): Redis
    {
        $parts = parse_url($this->redisUrl);
        if (!\is_array($parts) || !isset($parts['host'])) {
            throw new RuntimeException('REDIS_URL is invalid.');
        }

        $redis = new Redis;
        $redis->connect($parts['host'], (int) ($parts['port'] ?? 6379));

        if (isset($parts['pass'])) {
            $redis->auth($parts['pass']);
        }
        if (isset($parts['path']) && '' !== $parts['path'] && '/' !== $parts['path']) {
            $database = ltrim($parts['path'], '/');
            if (ctype_digit($database)) {
                $redis->select((int) $database);
            }
        }

        return $redis;
    }
}
