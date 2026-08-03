<?php

declare(strict_types=1);

namespace App\Service\Basketball;

/**
 * FFBB season code ↔ our July-15 pivot (P1-4 PR F, §8.2 of the appariement
 * spec, closed): « 26-27 » is the season whose SeasonResolver::seasonYear is
 * 2026. Pure — one place for the mapping, never re-derived inline.
 */
final class FfbbSeasonCode
{
    /** « 26-27 » for season-year 2026. */
    public static function fromSeasonYear(int $seasonYear): string
    {
        return \sprintf('%02d-%02d', $seasonYear % 100, ($seasonYear + 1) % 100);
    }

    public static function matchesSeasonYear(?string $code, int $seasonYear): bool
    {
        return null !== $code && self::fromSeasonYear($seasonYear) === $code;
    }
}
