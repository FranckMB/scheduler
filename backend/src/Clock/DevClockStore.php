<?php

declare(strict_types=1);

namespace App\Clock;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Persists the dev-simulated "now" in the shared Redis cache so BOTH the web
 * process and the separate cron-runner container see the same simulated clock.
 * Dev-only tooling — nothing writes here in prod (the SimulatedClock override is
 * only wired in the dev environment).
 */
final class DevClockStore
{
    private const KEY = 'dev.clock.fixed_now';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {}

    /** The pinned instant, or null when the clock runs at real time. */
    public function get(): ?DateTimeImmutable
    {
        $item = $this->cache->getItem(self::KEY);
        if (!$item->isHit()) {
            return null;
        }
        $iso = $item->get();
        if (!\is_string($iso)) {
            return null;
        }
        // A corrupt/stale value must never 500 the season-resolution hot path:
        // treat an unparseable pin as "no pin" (real time).
        try {
            return new DateTimeImmutable($iso);
        } catch (Exception) {
            return null;
        }
    }

    /** Pin the clock to a given instant, or null to release it back to real time. */
    public function set(?DateTimeImmutable $at): void
    {
        if (null === $at) {
            $this->cache->deleteItem(self::KEY);

            return;
        }
        $item = $this->cache->getItem(self::KEY);
        // No expiry — a pinned clock stays until explicitly reset.
        $item->set($at->format(DateTimeInterface::ATOM));
        $this->cache->save($item);
    }
}
