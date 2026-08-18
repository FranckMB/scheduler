<?php

declare(strict_types=1);

namespace App\Deletion;

/**
 * Le libellé d'une ligne d'impact, **porté par le serveur** et non par l'écran.
 *
 * ⚑ C'est un choix, et c'est le cœur de P3-16 : tant que les libellés vivaient côté front,
 * ajouter une famille au cascade sans lui écrire un libellé la faisait disparaître de la
 * modale **en silence** — le gestionnaire confirmait une destruction qu'on ne lui avait pas
 * annoncée. Le libellé voyage donc avec le compte, depuis la seule liste qui fait foi.
 */
final readonly class ImpactLabel
{
    public function __construct(
        /** Clé stable (jamais affichée) — sert aux tests et au tri. */
        public string $key,
        public string $one,
        public string $many,
    ) {}
}
