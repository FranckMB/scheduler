<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-37 — la MAISON UNIQUE de « ce que les fermetures datées font à un plan de période ».
 *
 * Une indisponibilité de gymnase est une contrainte datée `venue_closed` portée par l'entrée
 * de calendrier (ADR-0002 inv. 5 : la datée décrit le FAIT, elle reste au calendrier). Trois
 * consommateurs en dérivent la même chose et doivent le faire de LA MÊME façon :
 *  - `OrphanPinGuard` (fermé-total = gymnase inerte, à SKIPPER ; jour fermé partiel = bloquant) ;
 *  - `VenuePeriodOverrideStateProcessor` (D2 — pas de mode sur un gymnase fermé-total) ;
 *  - `ReservationStateProcessor` (D3 — pas de réservation sur un gymnase fermé-total ou un jour fermé).
 *
 * La fenêtre et la SOURCE des datées suivent {@see CalendarEntry::datedConstraintSourceId} :
 * une semaine ENFANT hérite des fermetures de sa MÈRE, recoupées sur sa propre fenêtre.
 */
final class PlanVenueClosures
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /**
     * Un libellé HUMAIN des fermetures d'un gymnase (titre + bornes j/n), ou null si aucune.
     * Aucun identifiant interne — que des données saisies par le gestionnaire
     * (`PublicTextIsFreeOfInternalIdentifiersTest`).
     *
     * @param list<array{constraintId: string, venueId: string, title: string, startDate: string, endDate: string, weekdays: list<int>}> $summaries
     */
    public static function describeForVenue(array $summaries, string $venueId): ?string
    {
        $labels = [];
        foreach ($summaries as $summary) {
            if ($summary['venueId'] !== $venueId) {
                continue;
            }
            $labels[] = \sprintf(
                '« %s » (du %s au %s)',
                $summary['title'],
                self::humanDate($summary['startDate']),
                self::humanDate($summary['endDate']),
            );
        }

        return [] === $labels ? null : implode(' ; ', $labels);
    }

    /** Date `Y-m-d` en `j/n` humain (« 20/10 »), sans zéro de tête. */
    private static function humanDate(string $isoDate): string
    {
        return new DateTimeImmutable($isoDate)->format('j/n');
    }

    /**
     * @return array{
     *   fullyClosedVenueIds: array<string, true>,
     *   closedWeekdaysByVenue: array<string, array<int, true>>,
     *   summaries: list<array{constraintId: string, venueId: string, title: string, startDate: string, endDate: string, weekdays: list<int>}>
     * }
     */
    public function forPlan(string $schedulePlanId): array
    {
        $empty = ['fullyClosedVenueIds' => [], 'closedWeekdaysByVenue' => [], 'summaries' => []];
        $entry = $this->periodEntry($schedulePlanId);
        if (!$entry instanceof CalendarEntry) {
            return $empty; // un plan SEASON (ou introuvable) n'a pas de fermeture de période
        }
        $dated = $this->entityManager->getRepository(Constraint::class)
            ->findBy(['calendarEntryId' => $entry->datedConstraintSourceId()]);
        $start = $entry->getStartDate();
        $end = $entry->getEndDate();

        $fully = [];
        foreach (VenueClosureDays::fullyClosedVenueIds($dated, $start, $end) as $venueId) {
            $fully[$venueId] = true;
        }

        return [
            'fullyClosedVenueIds' => $fully,
            'closedWeekdaysByVenue' => VenueClosureDays::closedWeekdaysByVenue($dated, $start, $end),
            'summaries' => VenueClosureDays::closureSummaries($dated, $start, $end),
        ];
    }

    private function periodEntry(string $schedulePlanId): ?CalendarEntry
    {
        $entryId = $this->entityManager->getConnection()->fetchOne(
            'SELECT calendar_entry_id FROM schedule_plan WHERE id = :pid',
            ['pid' => $schedulePlanId],
        );
        if (!\is_string($entryId) || '' === $entryId) {
            return null;
        }

        return $this->entityManager->getRepository(CalendarEntry::class)->find($entryId);
    }
}
