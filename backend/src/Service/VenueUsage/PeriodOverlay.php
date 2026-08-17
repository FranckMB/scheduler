<?php

declare(strict_types=1);

namespace App\Service\VenueUsage;

use DateTimeImmutable;

/**
 * Une période ACTIVE dont le plan porte une version validée (chosenScheduleId) :
 * sur sa fenêtre, ses créneaux remplacent la grille de saison (P3-22). Seules les
 * périodes AVEC une version pointée sont passées au calculateur ; `parentEntryId`
 * non nul marque une semaine-enfant, qui prime sa période mère.
 */
final readonly class PeriodOverlay
{
    public function __construct(
        public DateTimeImmutable $startDate,
        public DateTimeImmutable $endDate,
        public ?string $parentEntryId,
        public string $scheduleId,
    ) {}
}
