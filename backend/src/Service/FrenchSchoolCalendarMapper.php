<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/**
 * Pure, network-free mapping of the Éducation nationale ODS calendar
 * (dataset fr-en-calendrier-scolaire) onto our SchoolHolidayPeriod model.
 * Extracted from ImportSchoolHolidaysCommand so the vacation/zone/date rules
 * are unit-testable without a kernel or HTTP. See specs/evolution/roadmap.md §2.
 *
 * Rules (decided 2026-07-06):
 *  - A record is a VACATION iff it spans strictly more than 3 business days
 *    (Mon–Fri) AND its label does not start with "Pont" (the Ascension bridge
 *    spans 4–5 calendar days but only 3 business days, and is a manager/holiday
 *    concern, not a school vacation — handled by the future jours-fériés feature).
 *  - holidayType is an OPEN slug derived from the label (métropole + overseas
 *    labels differ: austral winter/summer, carnaval, février…).
 *  - zone label ("Zone A", "Corse", "Guadeloupe"…) → internal code.
 */
final class FrenchSchoolCalendarMapper
{
    /** @var array<string, string> normalized API zone label → internal zone code */
    private const ZONE_LABEL_TO_CODE = [
        'zone a' => 'A',
        'zone b' => 'B',
        'zone c' => 'C',
        'corse' => 'CORSE',
        'guadeloupe' => 'GUADELOUPE',
        'guyane' => 'GUYANE',
        'martinique' => 'MARTINIQUE',
        'mayotte' => 'MAYOTTE',
        'nouvelle caledonie' => 'NOUVELLE_CALEDONIE',
        'polynesie' => 'POLYNESIE',
        'reunion' => 'REUNION',
        'saint pierre et miquelon' => 'SAINT_PIERRE_MIQUELON',
        'wallis et futuna' => 'WALLIS_FUTUNA',
    ];

    /**
     * Maps an API `zones` label to our internal zone code, or null if unknown
     * (caller warns + skips — never crashes on a 14th/renamed label).
     */
    public function mapZone(string $apiZone): ?string
    {
        return self::ZONE_LABEL_TO_CODE[$this->normalize($apiZone)] ?? null;
    }

    /**
     * Derives the open holidayType slug from the free-text label:
     * strips the leading "Vacances (de la|de l'|de|d'|des|du)" then snake-cases
     * the remainder. "Vacances de la Toussaint" → "toussaint",
     * "Vacances d'Été austral" → "ete_austral", "Semaine en mai" → "semaine_en_mai".
     */
    public function mapHolidayType(string $description): string
    {
        $n = $this->normalize($description);
        $n = preg_replace('/^vacances\s+(de\s+la\s+|de\s+l\'|de\s+|d\'|des\s+|du\s+)?/', '', $n) ?? $n;
        $slug = preg_replace('/[^a-z0-9]+/', '_', $n) ?? $n;

        // Cap to the holiday_type column width (VARCHAR(40)) so an unusually long
        // open label can never overflow on insert.
        return mb_substr(trim($slug, '_'), 0, 40);
    }

    /**
     * A record is a real school vacation iff it spans > 3 business days and is
     * not a "Pont …". `endExclusive` is the API return-to-school date (school
     * resumes that day), so the off-span is [start, endExclusive).
     */
    public function isVacation(string $description, DateTimeImmutable $start, DateTimeImmutable $endExclusive): bool
    {
        if (str_starts_with($this->normalize($description), 'pont')) {
            return false;
        }

        return $this->businessDays($start, $endExclusive) > 3;
    }

    /**
     * Counts Mon–Fri days in [start, endExclusive). Public holidays are NOT
     * excluded (no fériés calendar in this scope — deterministic weekday count).
     */
    public function businessDays(DateTimeImmutable $start, DateTimeImmutable $endExclusive): int
    {
        if ($start >= $endExclusive) {
            return 0;
        }

        $count = 0;
        for ($day = $start; $day < $endExclusive; $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') <= 5) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The current school year + the next one, as ["2025-2026", "2026-2027"].
     * A school year rolls in August: before August, we are still in the year
     * that started the previous calendar year.
     *
     * @return array{0: string, 1: string}
     */
    public function schoolYearWindow(DateTimeImmutable $reference): array
    {
        $year = (int) $reference->format('Y');
        $month = (int) $reference->format('n');
        $startYear = $month >= 8 ? $year : $year - 1;

        return [
            \sprintf('%d-%d', $startYear, $startYear + 1),
            \sprintf('%d-%d', $startYear + 1, $startYear + 2),
        ];
    }

    /**
     * Lowercase, accent-stripped, hyphen/whitespace-collapsed form used for all
     * label matching — deterministic (no intl dependency) so tests are stable.
     */
    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            '’' => '\'', // typographic apostrophe (U+2019) → ASCII, so "d’Été" strips like "d'Été"
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
        ]);

        return trim((string) preg_replace('/[\s\-]+/', ' ', $value));
    }
}
