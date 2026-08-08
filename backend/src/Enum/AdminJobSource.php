<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * D'où part une exécution de job d'exploitation — foyer unique (audit D-19, 2026-08-09).
 *
 * `SUPERADMIN` est la seule source PORTEUSE D'IDENTITÉ : c'est elle qui accompagne un
 * `super_admin_id` dans l'historique (SA4, « quelle action, sur quel club, par qui »).
 * Les deux autres sont des exécutions machine.
 *
 * ⚠ `fromCommandLine()` n'expose **que** `cli` et `scheduled` : la porte console ne doit
 * jamais permettre de se déclarer `superadmin`, ce qui inscrirait dans l'historique une
 * action humaine que personne n'a faite. Ce n'est pas une copie incomplète de l'enum,
 * c'est un sous-ensemble voulu — et le garde le sait.
 */
enum AdminJobSource: string
{
    use HasValues;

    /** Les sources qu'un opérateur peut réclamer via `--source` — jamais `superadmin`. */
    public static function fromCommandLine(string $value): ?self
    {
        $source = self::tryFrom($value);

        return self::SUPERADMIN === $source ? null : $source;
    }

    case SCHEDULED = 'scheduled';
    case CLI = 'cli';
    case SUPERADMIN = 'superadmin';
}
