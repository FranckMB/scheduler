<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Le statut d'une exécution de job d'exploitation — foyer unique (audit D-19, 2026-08-09).
 *
 * Les quatre valeurs vivaient en **littéraux dispersés** : la contrainte `CHECK` SQL
 * (`Version20260716120000`), le store, le runner, l'écran d'erreurs système et le schéma
 * OpenAPI. Chacun avait sa copie, aucun ne dérivait de l'autre.
 *
 * ⚑ **Les deux sens de dérive ne se ressemblent pas.** Écrire un statut absent du `CHECK`
 * lève une contrainte Postgres : c'est BRUYANT, ça casse le job en cours et ça se voit.
 * Mais l'inverse — le `CHECK` accepte un statut que le code ne LIT plus nulle part — est
 * parfaitement silencieux : une exécution ratée cesse d'apparaître dans
 * `AdminSystemErrorsController`, et la console d'exploitation affiche zéro erreur en
 * annonçant que tout va bien. C'est le sens dangereux, et c'est celui qu'un `CHECK` ne garde pas.
 *
 * `AdminJobEnumsMatchTheCheckConstraintTest` fait le diff dans les DEUX sens.
 */
enum AdminJobStatus: string
{
    use HasValues;

    /**
     * Les statuts qu'un exploitant doit voir comme une anomalie — la source « job » du flux
     * d'erreurs système. `INTERRUPTED` en fait partie : un job tué en cours (redémarrage, OOM)
     * ne rend jamais de code de sortie, donc il n'apparaîtrait nulle part sans cela.
     *
     * @return list<string>
     */
    public static function faultyValues(): array
    {
        return [self::FAILED->value, self::INTERRUPTED->value];
    }

    case RUNNING = 'running';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case INTERRUPTED = 'interrupted';
}
