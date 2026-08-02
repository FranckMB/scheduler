<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/**
 * THE rule « which training calendar applies on a given date » (ADR-0002),
 * extracted from MatchConflictDetector so every consumer shares one truth
 * (P1-4 PR B: the venue-unavailability impact needs it too — two copies of a
 * capture rule inevitably diverge, §7.2.1).
 *
 * An active period covering the date CAPTURES it — its overlay (may be null →
 * no training at all, a closure) wins; the first covering period in the
 * ordered list decides. Outside any period, the season's own calendar (the
 * version its plan points at) applies.
 *
 * Pure/stateless — the caller loads the scoped context (see
 * TrainingCalendarContext) and passes it in.
 */
final class EffectiveScheduleResolver
{
    /**
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}> $activePeriods ordered
     */
    public function resolve(DateTimeImmutable $date, array $activePeriods, ?string $seasonScheduleId): ?string
    {
        foreach ($activePeriods as $period) {
            if ($date >= $period['start'] && $date <= $period['end']) {
                return $period['scheduleId'];
            }
        }

        return $seasonScheduleId;
    }
}
