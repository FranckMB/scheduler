<?php

declare(strict_types=1);

namespace App\Export;

use App\Entity\ScheduleSlotTemplate;

/**
 * Resolved data for one schedule export, shared by the PDF/PNG grid generator
 * and the Excel generator so the slot fetch + name/colour maps live in one place
 * (previously copy-pasted across both). Rendering diverges; the data does not.
 *
 * @phpstan-type VenueInfo array{name: string, color: string|null}
 */
final readonly class ScheduleExportData
{
    /** Monday→Sunday; dayOfWeek is 1..7 ISO (the training week is usually 1..6). */
    public const DAY_LABELS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

    /**
     * @param list<ScheduleSlotTemplate> $slots          scoped to a venue when the export is
     * @param array<string, string>      $teamNames
     * @param array<string, string>      $teamCategories teamId → category name
     * @param array<string, VenueInfo>   $venues
     * @param array<string, string>      $coachNames
     * @param list<ExportEmptyWindow>    $emptySlots     defined-but-unfilled venue windows
     */
    public function __construct(
        public array $slots,
        public array $teamNames,
        public array $teamCategories,
        public array $venues,
        public array $coachNames,
        public array $emptySlots = [],
    ) {}
}
