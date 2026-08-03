<?php

declare(strict_types=1);

namespace App\Service;

use Redis;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Anti-double-clic of the synchronous match placement (P1-4 PR D) — the exact
 * ClubGenerationLock pattern (SETEX NX + token compare-and-delete) under its
 * OWN prefix: generating a weekly plan and placing matches touch disjoint
 * data (nothing of the match module enters the weekly payload), so neither
 * gesture must ever block the other.
 */
class MatchPlacementLock
{
    private const KEY_PREFIX = 'match_placement:club:';

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

        // Atomic compare-and-delete (BCK-02 idiom): only the token holder may
        // delete, check + delete in one Redis operation.
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
