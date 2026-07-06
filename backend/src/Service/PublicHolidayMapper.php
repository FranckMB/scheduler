<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/**
 * Pure, network-free mapping of the etalab jours-fériés files
 * (calendrier.api.gouv.fr/jours-feries/{zone}.json, flat { "YYYY-MM-DD": label })
 * onto our PublicHoliday model. Extracted from ImportPublicHolidaysCommand so the
 * diff/zone/window rules are unit-testable without a kernel or HTTP.
 *
 * Model: métropole fériés → zone NATIONAL (apply to all clubs). Each DOM/TOM file
 * = métropole ∪ territory extras; we keep only the extras (diff against métropole)
 * tagged with that territory's SchoolZoneResolver::ZONES code. Alsace-Moselle and
 * Saint-Barthélemy/Saint-Martin are intentionally not imported (no zone home).
 * See specs/evolution/roadmap.md §2.
 */
final class PublicHolidayMapper
{
    /** @var array<string, string> etalab territory file slug → internal zone code */
    public const TERRITORY_FILE_TO_ZONE = [
        'guadeloupe' => 'GUADELOUPE',
        'martinique' => 'MARTINIQUE',
        'guyane' => 'GUYANE',
        'la-reunion' => 'REUNION',
        'mayotte' => 'MAYOTTE',
        'saint-pierre-et-miquelon' => 'SAINT_PIERRE_MIQUELON',
        'nouvelle-caledonie' => 'NOUVELLE_CALEDONIE',
        'polynesie' => 'POLYNESIE',
        'wallis' => 'WALLIS_FUTUNA',
    ];

    /**
     * The current calendar year and the next one. Fériés are keyed by CALENDAR
     * year (not school year → no August roll, unlike FrenchSchoolCalendarMapper).
     *
     * @return array{0: int, 1: int}
     */
    public function yearWindow(DateTimeImmutable $reference): array
    {
        $year = (int) $reference->format('Y');

        return [$year, $year + 1];
    }

    /**
     * National (métropole) fériés within [fromYear, toYear], as normalized rows.
     *
     * @param array<array-key, mixed> $metropole raw etalab payload { "YYYY-MM-DD": label }
     *
     * @return list<array{date: DateTimeImmutable, label: string}>
     */
    public function nationalHolidays(array $metropole, int $fromYear, int $toYear): array
    {
        return $this->rowsInWindow($metropole, $fromYear, $toYear);
    }

    /**
     * Territory-specific fériés = dates present in the territory file but NOT in
     * métropole (each territory file is métropole ∪ its extras), within the window.
     *
     * @param array<array-key, mixed> $metropole
     * @param array<array-key, mixed> $territory
     *
     * @return list<array{date: DateTimeImmutable, label: string}>
     */
    public function territoryExtras(array $metropole, array $territory, int $fromYear, int $toYear): array
    {
        return $this->rowsInWindow(array_diff_key($territory, $metropole), $fromYear, $toYear);
    }

    /**
     * @param array<array-key, mixed> $entries raw etalab payload (values are
     *                                         untrusted — non-string labels skipped)
     *
     * @return list<array{date: DateTimeImmutable, label: string}>
     */
    private function rowsInWindow(array $entries, int $fromYear, int $toYear): array
    {
        $rows = [];
        foreach ($entries as $rawDate => $rawLabel) {
            $date = \is_string($rawDate) ? $this->parseDate($rawDate) : null;
            $label = \is_string($rawLabel) ? trim($rawLabel) : '';
            if (null === $date || '' === $label) {
                continue;
            }
            $year = (int) $date->format('Y');
            if ($year < $fromYear || $year > $toYear) {
                continue;
            }
            $rows[] = ['date' => $date, 'label' => mb_substr($label, 0, 120)];
        }

        return $rows;
    }

    /**
     * Strict "YYYY-MM-DD" → DateTimeImmutable; null on any other shape or a
     * rollover date (e.g. 2026-13-01). Returns the parsed value so callers reuse
     * it instead of re-parsing.
     */
    private function parseDate(string $date): ?DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if (false === $parsed || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $parsed;
    }
}
