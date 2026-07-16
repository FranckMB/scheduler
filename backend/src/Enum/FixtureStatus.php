<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Home-fixture placement lifecycle (spec gestion-matchs, workflow 2-temps):
 * UNPLACED → PLACED (venue + kickoff set) → SUBMITTED (entered in FBI, sent to
 * the league) → VALIDATED (league confirmed).
 *
 * L'import FBI crée TOUT en UNPLACED — domicile ET extérieur (`FbiFixtureImporter` :
 * « Status is always UNPLACED »). Seul un geste explicite du gestionnaire
 * (`FixtureStateProcessor`) pose un autre statut ; une Heure FBI ne fait que
 * pré-remplir `kickoffTime`.
 *
 * Ce statut ne dit RIEN de l'engagement de l'équipe : dès que l'import a fait
 * correspondre une rencontre à une de nos équipes, la fédération la connaît — elle est
 * engagée, `UNPLACED` ou non (voir `TeamEngagementGuard`). Ne pas s'en servir pour
 * répondre « cette équipe joue-t-elle ? ».
 */
enum FixtureStatus: string
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
    case UNPLACED = 'UNPLACED';
    case PLACED = 'PLACED';
    case SUBMITTED = 'SUBMITTED';
    case VALIDATED = 'VALIDATED';
}
