<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ImplicitConstraint;

final class ImplicitConstraintConfig
{
    /**
     * Version du JEU DE RÈGLES implicites (à ne pas confondre avec `CONTRACT_VERSION`, qui
     * versionne la forme du payload). Elle vivait en dur dans `ExportImplicitConstraintsCommand`
     * pendant que `engine/implicit_rules.json` en annonçait une autre — deux déclarations que
     * rien ne comparait (D-43). Gardée par `ImplicitRulesMatchEngineTest`.
     *
     * 2.3 → 2.4 (P2-28 PR 2) : l'inventaire passe de 5 à 12 règles — les 6 « règles du produit »
     * immuables, les 4 de « bien-être » réglables (contrat 2.7), et 2 extensions futures encore
     * DÉSACTIVÉES (stubs moteur `travel_feasibility` / `required_bridge`).
     */
    public const string RULESET_VERSION = '2.4';

    /**
     * L'inventaire des règles implicites, aligné sur `engine/implicit_rules.json` (mêmes
     * `type`/`enabled`/`description`, gardé par `ImplicitRulesMatchEngineTest`). Deux familles :
     * `PRODUCT` (règle du jeu, immuable) et `WELLNESS` (bien-être, réglable par club/saison —
     * intensité `HARD`/`PREFERRED` + seuil). Les entrées `enabled=false` sont des extensions
     * futures : le moteur porte un stub (0 contrainte), le produit ne les propose pas encore.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'venueAtMostOne' => [
                'type' => ImplicitConstraint::VENUE_AT_MOST_ONE->value,
                'enabled' => true,
                'family' => 'PRODUCT',
                // P4-65 (2026-08-09) — « max one team » décrivait une règle que le moteur
                // n'applique pas : `add_room_at_most_one` borne à la CAPACITÉ du créneau,
                // lue du payload (`trainingSlots[].capacity`, défaut 1). 5 créneaux la
                // dépassent en base, et c'est ce qui rend possible le cas D-14 (deux équipes,
                // un coach, même gymnase). Le docstring du moteur était juste depuis toujours ;
                // seuls les deux inventaires mentaient, exactement comme pour COACH_NO_OVERLAP.
                'description' => 'One venue hosts at most its slot capacity of teams per time slot (capacity comes from the payload, default 1)',
            ],
            'coachNoOverlap' => [
                'type' => ImplicitConstraint::COACH_NO_OVERLAP->value,
                'enabled' => true,
                'family' => 'PRODUCT',
                // D-14 (2026-08-09) — la formulation « max one team per time slot » décrivait
                // la règle que le MOTEUR appliquait, pas celle que le produit veut : le même
                // gymnase est autorisé (un coach y surveille deux groupes). Le backend et la
                // modale l'exemptaient déjà ; le moteur a été aligné, et cette phrase avec.
                'description' => 'One coach coaches at most one team per time slot, EXCEPT in the same venue (D-14: a coach can watch two groups side by side; different venues remain impossible)',
            ],
            'coachPlayerNoOverlap' => [
                'type' => ImplicitConstraint::COACH_PLAYER_NO_OVERLAP->value,
                'enabled' => true,
                'family' => 'PRODUCT',
                'description' => 'A coach-player cannot be in two roles simultaneously',
            ],
            'teamNoOverlap' => [
                'type' => ImplicitConstraint::TEAM_NO_OVERLAP->value,
                'enabled' => true,
                'family' => 'PRODUCT',
                'description' => 'A team cannot have two sessions at the same time',
            ],
            'minSessions' => [
                'type' => ImplicitConstraint::MIN_SESSIONS->value,
                'enabled' => true,
                'family' => 'PRODUCT',
                // D-43 — disait « gets at least », donc un PLANCHER DUR, alors que le solveur
                // n'en fait qu'une CIBLE (bonus d'objectif soft) depuis ENG-18. Le moteur avait
                // été recalé, pas le backend : les deux descriptions se contredisaient et rien
                // ne les comparait. Formulation alignée sur `engine/implicit_rules.json`.
                'description' => 'Each team TARGETS its effective minimum sessions (soft objective bonus, not a hard floor - ENG-18)',
            ],
            'oneSessionPerDay' => [
                'type' => ImplicitConstraint::ONE_SESSION_PER_DAY->value,
                'enabled' => true,
                'family' => 'PRODUCT',
                'description' => 'A team gets at most one session per day (no exception - the allowMultipleSessionsPerDay flag was removed, P4-79)',
            ],
            'coachRestDay' => [
                'type' => ImplicitConstraint::COACH_REST_DAY->value,
                'enabled' => true,
                'family' => 'WELLNESS',
                'defaultIntensity' => 'HARD',
                'description' => 'Each coach keeps at least minRestDays rest day(s) per week (Mon-Fri); HARD by default, PREFERRED relaxes it to a penalized objective term',
            ],
            'salarieDistribution' => [
                'type' => ImplicitConstraint::SALARIE_DISTRIBUTION->value,
                'enabled' => true,
                'family' => 'WELLNESS',
                'defaultIntensity' => 'HARD',
                'description' => 'At least one salaried coach (isEmployee) is present each weekday (Mon-Fri); skipped when the club has fewer than two salaried coaches',
            ],
            'maxConsecutiveSessions' => [
                'type' => ImplicitConstraint::MAX_CONSECUTIVE_SESSIONS->value,
                'enabled' => true,
                'family' => 'WELLNESS',
                'defaultIntensity' => 'HARD',
                'description' => 'No person chains maxConsecutive back-to-back sessions across venues; HARD by default, PREFERRED relaxes it to a penalized objective term',
            ],
            'ageAscending' => [
                'type' => ImplicitConstraint::AGE_ASCENDING->value,
                'enabled' => true,
                'family' => 'WELLNESS',
                'defaultIntensity' => 'HARD',
                'description' => 'Younger teams train no later than older ones on the same venue and day (exempt when a team has no ageMin or is hard-locked)',
            ],
            'travelFeasibility' => [
                'type' => ImplicitConstraint::TRAVEL_FEASIBILITY->value,
                'enabled' => false,
                'family' => 'PRODUCT',
                'note' => 'DÉSACTIVÉE = extension future',
                'description' => 'DISABLED (future extension): travel-time feasibility between venues - engine stub, always satisfied, posts no constraint',
            ],
            'requiredBridge' => [
                'type' => ImplicitConstraint::REQUIRED_BRIDGE->value,
                'enabled' => false,
                'family' => 'PRODUCT',
                'note' => 'DÉSACTIVÉE = extension future',
                'description' => 'DISABLED (future extension): required bridging sessions - engine stub, always satisfied, posts no constraint',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getConstraintsArray(): array
    {
        return array_values($this->getConfig());
    }
}
