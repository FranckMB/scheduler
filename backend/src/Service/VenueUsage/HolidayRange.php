<?php

declare(strict_types=1);

namespace App\Service\VenueUsage;

use DateTimeImmutable;

/** Une fenêtre de vacances scolaires (bornes incluses) de la zone du club (P3-22). */
final readonly class HolidayRange
{
    public function __construct(
        public DateTimeImmutable $startDate,
        public DateTimeImmutable $endDate,
    ) {}
}
