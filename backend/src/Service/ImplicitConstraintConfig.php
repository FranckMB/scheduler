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
     */
    public const string RULESET_VERSION = '2.2';

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'venueAtMostOne' => [
                'type' => ImplicitConstraint::VENUE_AT_MOST_ONE->value,
                'enabled' => true,
                'description' => 'One venue hosts max one team per time slot',
            ],
            'coachNoOverlap' => [
                'type' => ImplicitConstraint::COACH_NO_OVERLAP->value,
                'enabled' => true,
                // D-14 (2026-08-09) — la formulation « max one team per time slot » décrivait
                // la règle que le MOTEUR appliquait, pas celle que le produit veut : le même
                // gymnase est autorisé (un coach y surveille deux groupes). Le backend et la
                // modale l'exemptaient déjà ; le moteur a été aligné, et cette phrase avec.
                'description' => 'One coach coaches at most one team per time slot, EXCEPT in the same venue (D-14: a coach can watch two groups side by side; different venues remain impossible)',
            ],
            'coachPlayerNoOverlap' => [
                'type' => ImplicitConstraint::COACH_PLAYER_NO_OVERLAP->value,
                'enabled' => true,
                'description' => 'A coach-player cannot be in two roles simultaneously',
            ],
            'teamNoOverlap' => [
                'type' => ImplicitConstraint::TEAM_NO_OVERLAP->value,
                'enabled' => true,
                'description' => 'A team cannot have two sessions at the same time',
            ],
            'minSessions' => [
                'type' => ImplicitConstraint::MIN_SESSIONS->value,
                'enabled' => true,
                // D-43 — disait « gets at least », donc un PLANCHER DUR, alors que le solveur
                // n'en fait qu'une CIBLE (bonus d'objectif soft) depuis ENG-18. Le moteur avait
                // été recalé, pas le backend : les deux descriptions se contredisaient et rien
                // ne les comparait. Formulation alignée sur `engine/implicit_rules.json`.
                'description' => 'Each team TARGETS its effective minimum sessions (soft objective bonus, not a hard floor - ENG-18)',
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
