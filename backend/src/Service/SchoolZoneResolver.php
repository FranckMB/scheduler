<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Derives a club's academic holiday zone (A/B/C) from its FFBB club code, which
 * encodes the department (accueil-cockpit-temporel.md §4bis). The department →
 * zone table is the official Éducation nationale grouping (metropolitan France;
 * Corse → B; overseas → null).
 *
 * ⚠ The FFBB-code → department extraction is BEST-EFFORT: the exact code format
 * is not officially verified, so extraction returns null when no metropolitan
 * department can be read. Zone is then left null and remains manually editable
 * on the club (Club.schoolZone via PATCH) — never overwritten when already set.
 */
final class SchoolZoneResolver
{
    /** @var array<int|string, string> department code → zone (A/B/C); numeric keys are int-coerced by PHP */
    private const DEPARTMENT_ZONE = [
        // Zone A
        '25' => 'A', '39' => 'A', '70' => 'A', '90' => 'A',
        '24' => 'A', '33' => 'A', '40' => 'A', '47' => 'A', '64' => 'A',
        '03' => 'A', '15' => 'A', '43' => 'A', '63' => 'A',
        '21' => 'A', '58' => 'A', '71' => 'A', '89' => 'A',
        '07' => 'A', '26' => 'A', '38' => 'A', '73' => 'A', '74' => 'A',
        '19' => 'A', '23' => 'A', '87' => 'A',
        '01' => 'A', '42' => 'A', '69' => 'A',
        '16' => 'A', '17' => 'A', '79' => 'A', '86' => 'A',
        // Zone B
        '04' => 'B', '05' => 'B', '13' => 'B', '84' => 'B',
        '02' => 'B', '60' => 'B', '80' => 'B',
        '59' => 'B', '62' => 'B',
        '54' => 'B', '55' => 'B', '57' => 'B', '88' => 'B',
        '44' => 'B', '49' => 'B', '53' => 'B', '72' => 'B', '85' => 'B',
        '06' => 'B', '83' => 'B',
        '18' => 'B', '28' => 'B', '36' => 'B', '37' => 'B', '41' => 'B', '45' => 'B',
        '08' => 'B', '10' => 'B', '51' => 'B', '52' => 'B',
        '22' => 'B', '29' => 'B', '35' => 'B', '56' => 'B',
        '14' => 'B', '27' => 'B', '50' => 'B', '61' => 'B', '76' => 'B',
        '67' => 'B', '68' => 'B',
        '2A' => 'B', '2B' => 'B',
        // Zone C
        '77' => 'C', '93' => 'C', '94' => 'C',
        '11' => 'C', '30' => 'C', '34' => 'C', '48' => 'C', '66' => 'C',
        '75' => 'C',
        '09' => 'C', '12' => 'C', '31' => 'C', '32' => 'C', '46' => 'C', '65' => 'C', '81' => 'C', '82' => 'C',
        '78' => 'C', '91' => 'C', '92' => 'C', '95' => 'C',
    ];

    public function resolveFromFfbbCode(?string $ffbbCode): ?string
    {
        $department = $this->extractDepartment($ffbbCode);
        if (null === $department) {
            return null;
        }

        return self::DEPARTMENT_ZONE[$department] ?? null;
    }

    public function zoneForDepartment(string $department): ?string
    {
        return self::DEPARTMENT_ZONE[strtoupper($department)] ?? null;
    }

    /**
     * Best-effort: Corse (2A/2B) first, then any 2-digit window (leading or
     * trailing of each digit run, covering both "69…" prefix and "…0069" forms)
     * that matches a known metropolitan department.
     */
    private function extractDepartment(?string $ffbbCode): ?string
    {
        if (null === $ffbbCode || '' === $ffbbCode) {
            return null;
        }

        $code = strtoupper($ffbbCode);

        if (str_contains($code, '2A')) {
            return '2A';
        }
        if (str_contains($code, '2B')) {
            return '2B';
        }

        preg_match_all('/\d+/', $code, $matches);
        foreach ($matches[0] as $run) {
            if (\strlen($run) < 2) {
                continue;
            }
            foreach ([substr($run, 0, 2), substr($run, -2)] as $candidate) {
                if (isset(self::DEPARTMENT_ZONE[$candidate])) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
