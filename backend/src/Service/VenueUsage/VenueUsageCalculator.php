<?php

declare(strict_types=1);

namespace App\Service\VenueUsage;

use App\Enum\TeamLevel;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;

/**
 * Statistiques d'utilisation des gymnases (P3-22).
 *
 * BUT MÉTIER : le gestionnaire NÉGOCIE ses créneaux avec la mairie. Le chiffre
 * d'entrée est « le lundi, on a 8 h de training » ; l'argument, « dont 3 h de
 * régional et 2 h de loisir ». Ce service produit donc, par gymnase ET par
 * niveau, les heures ventilées PAR JOUR de la semaine, en distinguant le déjà
 * RÉALISÉ (dates ≤ aujourd'hui, incluse) du reste À VENIR.
 *
 * Le calcul se fait JOUR PAR JOUR sur [from, to] — ce qui règle gratuitement les
 * semaines partielles (une plage qui commence un mercredi ne compte pas le lundi
 * et mardi d'avant). Pour chaque date on résout LA grille applicable :
 *   1. une période ACTIVE portant une version validée qui couvre la date → ses
 *      créneaux (la semaine-enfant prime sur sa période mère) ;
 *   2. sinon un jour de vacances scolaires de la zone du club → 0 h ;
 *   3. sinon la grille pointée du plan SEASON (aucun pointeur → 0 h).
 *
 * Service PUR : toutes les données sont injectées (aujourd'hui compris), aucun
 * accès base — c'est le contrôleur qui charge, ici on ne fait qu'agréger.
 *
 * @phpstan-type DayHours array{day: int, real: float, projected: float, total: float}
 * @phpstan-type VenueRow array{venueId: string, name: string, byDay: list<DayHours>, real: float, projected: float, total: float}
 * @phpstan-type LevelRow array{level: string|null, label: string, byDay: list<DayHours>, real: float, projected: float, total: float}
 * @phpstan-type UsageResult array{range: array{from: string, to: string, today: string}, zone: string|null, venues: list<VenueRow>, totalByDay: list<DayHours>, byLevel: list<LevelRow>, grandTotal: array{real: float, projected: float, total: float}}
 */
final class VenueUsageCalculator
{
    /** Clé et libellé de la ligne « équipes sans niveau renseigné ». */
    private const string UNCLASSIFIED_KEY = '__none__';

    private const string UNCLASSIFIED_LABEL = 'Non renseigné';

    /**
     * @param list<PeriodOverlay>            $overlays          périodes actives AVEC version validée
     * @param list<HolidayRange>             $holidays          vacances de la zone du club (vide si zone nulle → aucune neutralisation)
     * @param array<string, list<UsageSlot>> $slotsByScheduleId créneaux indexés par scheduleId
     * @param array<string, string>          $venueNames        venueId → nom d'affichage
     * @param array<string, TeamLevel|null>  $teamLevels        teamId → niveau (null = non renseigné)
     *
     * @return UsageResult
     */
    public function calculate(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        DateTimeImmutable $today,
        ?string $zone,
        ?string $seasonScheduleId,
        array $overlays,
        array $holidays,
        array $slotsByScheduleId,
        array $venueNames,
        array $teamLevels,
    ): array {
        $todayYmd = $today->format('Y-m-d');

        // Accumulateurs : venueId|clé-niveau → ['name'/'level'/'label', 'days' => day => ['real','projected']].
        /** @var array<string, array{name: string, days: array<int, array{real: float, projected: float}>}> $venueAcc */
        $venueAcc = [];
        /** @var array<string, array{level: string|null, label: string, days: array<int, array{real: float, projected: float}>}> $levelAcc */
        $levelAcc = [];
        /** @var array<int, array{real: float, projected: float}> $totalDays */
        $totalDays = [];
        $grand = ['real' => 0.0, 'projected' => 0.0];

        foreach ($this->eachDay($from, $to) as $date) {
            $scheduleId = $this->resolveScheduleForDate($date, $overlays, $holidays, $seasonScheduleId);
            if (null === $scheduleId) {
                continue;
            }

            $dow = (int) $date->format('N');
            $bucket = $date->format('Y-m-d') <= $todayYmd ? 'real' : 'projected';

            foreach ($slotsByScheduleId[$scheduleId] ?? [] as $slot) {
                if ($slot->dayOfWeek !== $dow) {
                    continue;
                }
                $hours = $slot->durationMinutes / 60;

                $venueAcc[$slot->venueId] ??= ['name' => $venueNames[$slot->venueId] ?? '', 'days' => []];
                $venueAcc[$slot->venueId]['days'][$dow] ??= ['real' => 0.0, 'projected' => 0.0];
                $venueAcc[$slot->venueId]['days'][$dow][$bucket] += $hours;

                $level = $teamLevels[$slot->teamId] ?? null;
                $levelKey = $level instanceof TeamLevel ? $level->value : self::UNCLASSIFIED_KEY;
                $levelAcc[$levelKey] ??= [
                    'level' => $level instanceof TeamLevel ? $level->value : null,
                    'label' => $level instanceof TeamLevel ? $level->label() : self::UNCLASSIFIED_LABEL,
                    'days' => [],
                ];
                $levelAcc[$levelKey]['days'][$dow] ??= ['real' => 0.0, 'projected' => 0.0];
                $levelAcc[$levelKey]['days'][$dow][$bucket] += $hours;

                $totalDays[$dow] ??= ['real' => 0.0, 'projected' => 0.0];
                $totalDays[$dow][$bucket] += $hours;
                $grand[$bucket] += $hours;
            }
        }

        return [
            'range' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'today' => $todayYmd],
            'zone' => $zone,
            'venues' => $this->venueRows($venueAcc),
            'totalByDay' => $this->summarize($totalDays)['byDay'],
            'byLevel' => $this->levelRows($levelAcc),
            'grandTotal' => $this->roundedHours($grand['real'], $grand['projected']),
        ];
    }

    /**
     * La grille applicable un jour donné, ou null quand aucune séance ne compte
     * (jour de vacances neutralisé, ou plan SEASON sans version pointée).
     *
     * @param list<PeriodOverlay> $overlays
     * @param list<HolidayRange>  $holidays
     */
    private function resolveScheduleForDate(
        DateTimeImmutable $date,
        array $overlays,
        array $holidays,
        ?string $seasonScheduleId,
    ): ?string {
        $ymd = $date->format('Y-m-d');

        // 1. Période active portant une version validée. À couverture égale, la
        //    fenêtre la plus ÉTROITE gagne — c'est ainsi que la semaine-enfant
        //    prime sa période mère (plus large) ; à égalité de largeur, l'enfant
        //    (parentEntryId non nul) tranche.
        $best = null;
        $bestSpan = \PHP_INT_MAX;
        foreach ($overlays as $overlay) {
            if ($overlay->startDate->format('Y-m-d') > $ymd || $overlay->endDate->format('Y-m-d') < $ymd) {
                continue;
            }
            $span = (int) $overlay->startDate->diff($overlay->endDate)->days;
            $childBreaksTie = $span === $bestSpan && null !== $overlay->parentEntryId && (null === $best || null === $best->parentEntryId);
            if ($span < $bestSpan || $childBreaksTie) {
                $best = $overlay;
                $bestSpan = $span;
            }
        }
        if (null !== $best) {
            return $best->scheduleId;
        }

        // 2. Vacances scolaires → 0 h. DÉCISION FONDATEUR (à confirmer, BASCULABLE
        //    ICI EN UNE LIGNE) : un jour de vacances vaut 0 h MÊME en Réalisé —
        //    compter des heures « faites » quand personne ne s'est entraîné serait
        //    faux. Pour ne neutraliser que les vacances À VENIR (et laisser les
        //    vacances passées retomber sur la grille de saison), remplacer la
        //    condition ci-dessous par `$this->fallsInHoliday(...) && $ymd > $todayYmd`
        //    (et réintroduire `$todayYmd`, déjà connu de l'appelant).
        if ($this->fallsInHoliday($ymd, $holidays)) {
            return null;
        }

        // 3. Grille pointée du plan SEASON (null → 0 h).
        return $seasonScheduleId;
    }

    /**
     * La date (Y-m-d) tombe-t-elle dans une fenêtre de vacances (bornes incluses) ?
     *
     * @param list<HolidayRange> $holidays
     */
    private function fallsInHoliday(string $ymd, array $holidays): bool
    {
        foreach ($holidays as $holiday) {
            if ($holiday->startDate->format('Y-m-d') <= $ymd && $holiday->endDate->format('Y-m-d') >= $ymd) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array{name: string, days: array<int, array{real: float, projected: float}>}> $venueAcc
     *
     * @return list<VenueRow>
     */
    private function venueRows(array $venueAcc): array
    {
        $rows = [];
        foreach ($venueAcc as $venueId => $acc) {
            $summary = $this->summarize($acc['days']);
            $rows[] = [
                'venueId' => $venueId,
                'name' => $acc['name'],
                'byDay' => $summary['byDay'],
                'real' => $summary['real'],
                'projected' => $summary['projected'],
                'total' => $summary['total'],
            ];
        }
        // Le plus utilisé d'abord (le chiffre de négociation), nom en départage.
        usort($rows, static fn (array $a, array $b): int => $b['total'] <=> $a['total'] ?: strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * @param array<string, array{level: string|null, label: string, days: array<int, array{real: float, projected: float}>}> $levelAcc
     *
     * @return list<LevelRow>
     */
    private function levelRows(array $levelAcc): array
    {
        $rows = [];
        foreach ($levelAcc as $acc) {
            $summary = $this->summarize($acc['days']);
            $rows[] = [
                'level' => $acc['level'],
                'label' => $acc['label'],
                'byDay' => $summary['byDay'],
                'real' => $summary['real'],
                'projected' => $summary['projected'],
                'total' => $summary['total'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['total'] <=> $a['total'] ?: strcmp($a['label'], $b['label']));

        return $rows;
    }

    /**
     * Un accumulateur de jours → sa liste byDay (triée, arrondie) et ses totaux.
     *
     * @param array<int, array{real: float, projected: float}> $days
     *
     * @return array{byDay: list<DayHours>, real: float, projected: float, total: float}
     */
    private function summarize(array $days): array
    {
        ksort($days);
        $byDay = [];
        $real = 0.0;
        $projected = 0.0;
        foreach ($days as $day => $hours) {
            $rounded = $this->roundedHours($hours['real'], $hours['projected']);
            $byDay[] = ['day' => $day, 'real' => $rounded['real'], 'projected' => $rounded['projected'], 'total' => $rounded['total']];
            $real += $hours['real'];
            $projected += $hours['projected'];
        }

        $totals = $this->roundedHours($real, $projected);

        return ['byDay' => $byDay, 'real' => $totals['real'], 'projected' => $totals['projected'], 'total' => $totals['total']];
    }

    /**
     * Arrondit réalisé/à-venir à 2 décimales et en dérive le total (arrondi APRÈS
     * somme des bruts, pour que total == real + projected sans dérive d'arrondi).
     *
     * @return array{real: float, projected: float, total: float}
     */
    private function roundedHours(float $real, float $projected): array
    {
        return [
            'real' => round($real, 2),
            'projected' => round($projected, 2),
            'total' => round($real + $projected, 2),
        ];
    }

    /**
     * Les dates de [from, to], bornes incluses.
     *
     * @return list<DateTimeImmutable>
     */
    private function eachDay(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        // DatePeriod exclut la borne haute → on la pousse d'un jour. Dates civiles
        // à minuit : P1D ne réveille aucun piège d'heure d'été.
        $days = [];
        foreach (new DatePeriod($from, new DateInterval('P1D'), $to->modify('+1 day')) as $date) {
            $days[] = DateTimeImmutable::createFromInterface($date);
        }

        return $days;
    }
}
