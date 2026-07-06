<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Season;
use App\Repository\SeasonRepository;
use DateTimeImmutable;

/**
 * Resolves which of a club's seasons is the CURRENT one, derived from the
 * calendar — never from the `status` column (spec transition-de-saison §3:
 * "courante dérivée du calendrier", no cron flipping a flag, non-destructive
 * by construction). `Season.status` is display metadata only.
 *
 * The season year pivots on July 15 (TRANSITION_MONTH_DAY): from that date
 * the upcoming season (N+1) becomes current as soon as it exists. The rule
 * keys on startDate ONLY — endDate is untrusted (the register seed
 * historically wrote endDate < startDate) and seasons may gap or overlap.
 *
 * A club with exactly one season gets it as current unconditionally,
 * whatever its dates — the mono-season behaviour must be identical to the
 * pre-multi-season app.
 */
final class SeasonResolver
{
    /** Season switchover pivot (month-day): on/after this date we are in the season starting that year. */
    public const TRANSITION_MONTH_DAY = '07-15';

    public function __construct(
        private readonly SeasonRepository $seasonRepository,
    ) {}

    /**
     * List-based variant of isReadonly (no query — the caller holds the
     * club's seasons, e.g. /api/me building the selector payload).
     *
     * @param list<Season> $seasons ordered by startDate ASC
     */
    public static function isReadonlyAmong(Season $season, array $seasons, ?DateTimeImmutable $today = null): bool
    {
        $current = self::currentAmong($seasons, $today);
        if (null === $current || $current->getId() === $season->getId()) {
            return false;
        }

        return self::seasonYear($season->getStartDate()) < self::seasonYear($current->getStartDate());
    }

    /**
     * Same derivation from an already-loaded list (avoids a second query when
     * the caller holds the club's seasons, e.g. /api/me).
     *
     * @param list<Season> $seasons ordered by startDate ASC
     */
    public static function currentAmong(array $seasons, ?DateTimeImmutable $today = null): ?Season
    {
        if ([] === $seasons) {
            return null;
        }
        if (1 === \count($seasons)) {
            // Mono-season club: current unconditionally (zero-behaviour-change guarantee).
            return $seasons[0];
        }

        $todayYear = self::seasonYear($today ?? new DateTimeImmutable('today'));

        $candidate = null;
        foreach ($seasons as $season) {
            if (self::seasonYear($season->getStartDate()) <= $todayYear) {
                // List is startDate ASC → the last match has the greatest startDate.
                $candidate = $season;
            }
        }

        return $candidate ?? $seasons[0];
    }

    /**
     * The season-year a date belongs to: 2026-07-14 → 2025 (season 2025-26),
     * 2026-07-15 → 2026 (season 2026-27). Lexicographic 'm-d' compare is safe.
     */
    public static function seasonYear(DateTimeImmutable $date): int
    {
        $year = (int) $date->format('Y');

        return $date->format('m-d') >= self::TRANSITION_MONTH_DAY ? $year : $year - 1;
    }

    /**
     * All seasons of the club, oldest first (startDate ASC).
     *
     * @return list<Season>
     */
    public function seasonsForClub(string $clubId): array
    {
        return $this->seasonRepository->findAllByClubId($clubId);
    }

    /**
     * The current season: the season with the greatest startDate among those
     * whose season-year is not in the future. All-future edge case → the
     * earliest season (a club prepared ahead of time still has a season).
     */
    public function currentSeason(string $clubId, ?DateTimeImmutable $today = null): ?Season
    {
        return self::currentAmong($this->seasonsForClub($clubId), $today);
    }

    public function isCurrent(Season $season, ?DateTimeImmutable $today = null): bool
    {
        $current = $this->currentSeason($season->getClubId(), $today);

        return null !== $current && $current->getId() === $season->getId();
    }

    /**
     * A season is read-only when it belongs to a season-year strictly before
     * the current one (N-1 and older). The current season and future drafts
     * are writable.
     */
    public function isReadonly(Season $season, ?DateTimeImmutable $today = null): bool
    {
        return self::isReadonlyAmong($season, $this->seasonsForClub($season->getClubId()), $today);
    }
}
