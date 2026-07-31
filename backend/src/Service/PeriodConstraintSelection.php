<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Constraint;

/**
 * P2-14 — le résultat de LA sélection : quelles contraintes (entités) partent au solveur
 * pour un plan de période, et pourquoi les autres n'y partent pas. Produit par
 * {@see PeriodConstraintSelector}, consommé par les DEUX côtés qui répondaient chacun à
 * cette question dans leur coin : le payload (`ScheduleConstraintBuilder::buildForPeriodPlan`)
 * et le gate pré-solve (`ValidateConstraintsController`).
 *
 * Les cartes annexes (gymnases désactivés, équipes en pause, séances surchargées) sont
 * exposées parce que le payload en a besoin pour filtrer créneaux/équipes/réservations —
 * les recharger à côté referait exactement le doublon que cette classe supprime.
 */
final readonly class PeriodConstraintSelection
{
    /**
     * @param list<Constraint>                                     $kept                    les entités qui partent au solveur — LE jeu que le gate valide
     * @param list<array{constraint: Constraint, venueId: string}> $droppedForDisabledVenue sorties car elles nomment un gymnase désactivé — le gate en fait ses warnings
     * @param list<Constraint>                                     $dated                   les datées BRUTES de la période (avant sélection) — la granularité JOUR des
     *                                                                                      fermetures (`VenueClosureDays`) se calcule dessus, drops compris : une datée
     *                                                                                      `venue_closed` ne produit aucune ligne payload mais ferme des jours
     * @param array<string, true>                                  $disabledVenueIds        gymnases DISABLED pour ce plan (sparse)
     * @param array<string, true>                                  $deactivatedTeamIds      équipes désactivées pour ce plan (sparse)
     * @param array<string, int>                                   $sessionOverrides        teamId → séances/semaine surchargées pour la période
     */
    public function __construct(
        public string $schedulePlanId,
        public array $kept,
        public array $droppedForDisabledVenue,
        public array $dated,
        public array $disabledVenueIds,
        public array $deactivatedTeamIds,
        public array $sessionOverrides,
    ) {}
}
