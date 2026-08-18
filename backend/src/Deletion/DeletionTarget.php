<?php

declare(strict_types=1);

namespace App\Deletion;

/**
 * L'entité qu'on s'apprête à supprimer, réduite à ce dont une étape de cascade a besoin :
 * son id et sa portée (club + saison). Les entités sont **saison-scopées** et partagées par
 * tous les plannings — il n'existe pas de « suppression pour un seul planning » (P3-16).
 */
final readonly class DeletionTarget
{
    public function __construct(
        public string $id,
        public string $clubId,
        public string $seasonId,
    ) {}
}
