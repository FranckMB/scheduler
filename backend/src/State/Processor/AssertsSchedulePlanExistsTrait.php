<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\Entity\SchedulePlan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Refuse une ancre `schedulePlanId` qui ne désigne aucun plan (P4-30).
 *
 * Avant la FK, une ancre inventée créait une ligne ORPHELINE en silence :
 * jamais lue (`StructureSnapshotter` filtre `IS NULL`), jamais nettoyée. Depuis
 * la FK, la même requête remonterait une
 * `ForeignKeyConstraintViolationException` — donc un **500** sur une saisie
 * simplement invalide. Ni l'un ni l'autre n'est acceptable : c'est un 422.
 *
 * `null` reste licite et signifie « ligne de base, partagée par la saison »
 * (inv. 6) — surtout ne pas le confondre avec une ancre absente.
 *
 * Le filtre tenant scope déjà la lecture au club courant : un plan d'un AUTRE
 * club est donc introuvable ici et se solde par le même 422, sans révéler qu'il
 * existe ailleurs.
 */
trait AssertsSchedulePlanExistsTrait
{
    private function assertSchedulePlanExists(EntityManagerInterface $entityManager, ?string $schedulePlanId): void
    {
        if (null === $schedulePlanId) {
            return;
        }

        if (!$entityManager->getRepository(SchedulePlan::class)->find($schedulePlanId) instanceof SchedulePlan) {
            throw new UnprocessableEntityHttpException('Unknown schedule plan.');
        }
    }
}
