<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Constraint;
use App\Enum\ConstraintFamily;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;

/**
 * P2-5 5b — les JOURS où un gymnase est réellement fermé, dans une fenêtre donnée.
 *
 * Une contrainte datée `venue_closed` (FACILITY, `scopeTargetId` = le gymnase)
 * porte `config[startDate,endDate]` : l'incident. Un plan de période (semaine
 * enfant ou bloc) couvre sa propre fenêtre. Les jours FERMÉS du gymnase pour ce
 * plan = les jours de semaine ISO (1=lun..7=dim) présents dans
 * [incident ∩ fenêtre du plan]. L'engine ne connaît que le gymnase, pas les jours
 * (forbidden_assignments est day-blind) — d'où le retrait day-précis des créneaux
 * en amont, plutôt qu'un forbid tous-jours (décision fondateur : zéro engine).
 *
 * Fallback SANS dates dans le config (donnée legacy) : le gymnase est fermé sur
 * TOUTE la fenêtre du plan — jamais sous-contraint (comportement 5a préservé).
 */
final class VenueClosureDays
{
    /**
     * @param iterable<Constraint> $datedConstraints les contraintes datées de la période
     *
     * @return array<string, array<int, true>> venueId => set de jours ISO fermés (1..7)
     */
    public static function closedWeekdaysByVenue(iterable $datedConstraints, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array
    {
        $windowStartDay = $windowStart->format('Y-m-d');
        $windowEndDay = $windowEnd->format('Y-m-d');

        $closed = [];
        foreach ($datedConstraints as $constraint) {
            if (ConstraintFamily::FACILITY !== $constraint->getFamily() || !$constraint->getIsActive() || null === $constraint->getScopeTargetId()) {
                continue;
            }
            $venueId = $constraint->getScopeTargetId();
            $config = $constraint->getConfig();
            // Intersection [incident] ∩ [fenêtre du plan] ; fallback = la fenêtre entière.
            $incidentStart = self::isoDate($config['startDate'] ?? null) ?? $windowStartDay;
            $incidentEnd = self::isoDate($config['endDate'] ?? null) ?? $windowEndDay;
            $from = max($incidentStart, $windowStartDay);
            $to = min($incidentEnd, $windowEndDay);
            if ($from > $to) {
                continue; // incident disjoint de la fenêtre : rien de fermé ici
            }
            // DatePeriod end exclusif → +1 jour.
            $end = new DateTimeImmutable($to . ' +1 day');
            foreach (new DatePeriod(new DateTimeImmutable($from), new DateInterval('P1D'), $end) as $day) {
                $closed[$venueId][(int) $day->format('N')] = true;
            }
        }

        return $closed;
    }

    private static function isoDate(mixed $value): ?string
    {
        if (!\is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
