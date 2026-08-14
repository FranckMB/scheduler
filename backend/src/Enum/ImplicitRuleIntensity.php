<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * L'intensité d'une règle implicite « bien-être » (contrat moteur 2.7). `HARD` la pose en
 * contrainte dure (comportement historique, défaut d'ABSENCE de réglage) ; `PREFERRED` la
 * retire du dur et la pénalise dans l'objectif — le solveur la respecte quand il peut, la
 * transgresse quand il doit, et le DIT (diagnostic `implicit_rule_not_honored`).
 */
enum ImplicitRuleIntensity: string
{
    use HasValues;

    case HARD = 'HARD';
    case PREFERRED = 'PREFERRED';
}
