<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * L'intensité d'une passerelle CÔTÉ ENTRAÎNEMENT (lot PASSERELLES, arbitrage
 * fondateur n°1). Le nom porte « training » à dessein : cette intensité ne
 * gouverne QUE le solveur d'entraînement (PR-2) — le rail matchs, lui, garde
 * sa pénalité SOFT historique, insensible à ce réglage.
 *
 * `PREFERRED` (défaut) : le solveur d'entraînement PRÉFÈRE honorer la
 * passerelle (objectif souple). `MANDATORY` : il DOIT l'honorer (contrainte
 * dure). En PR-1 le bloc voyage jusqu'au moteur mais n'est PAS consommé.
 */
enum TeamLinkIntensity: string
{
    use HasValues;

    case PREFERRED = 'PREFERRED';

    case MANDATORY = 'MANDATORY';
}
