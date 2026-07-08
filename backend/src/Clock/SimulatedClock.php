<?php

declare(strict_types=1);

namespace App\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface as PsrClockInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\DatePoint;

/**
 * Dev-only clock: returns the pinned instant from DevClockStore when set,
 * otherwise the real time from the inner (native) clock. Wired as the app's
 * ClockInterface only in the dev environment, so prod always runs on real time.
 */
final class SimulatedClock implements ClockInterface, PsrClockInterface
{
    public function __construct(
        private readonly ClockInterface $inner,
        private readonly DevClockStore $store,
    ) {}

    public function now(): DatePoint
    {
        $pinned = $this->store->get();

        return DatePoint::createFromInterface($pinned ?? $this->inner->now());
    }

    public function sleep(float|int $seconds): void
    {
        $this->inner->sleep($seconds);
    }

    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        $clone = clone $this;

        return new self($this->inner->withTimeZone($timezone), $this->store);
    }

    /** Convenience for callers that just need a DateTimeImmutable. */
    public function nowImmutable(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->now());
    }
}
