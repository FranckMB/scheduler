<?php

declare(strict_types=1);

namespace App\Export;

use DateTimeImmutable;

/**
 * A defined venue availability window the solver left unfilled ("créneau vide"),
 * surfaced in the PDF/XLSX export exactly as it is in the on-screen grid.
 */
final readonly class ExportEmptyWindow
{
    public function __construct(
        public string $venueId,
        public int $dayOfWeek,
        public DateTimeImmutable $startTime,
        public int $durationMinutes,
    ) {}
}
