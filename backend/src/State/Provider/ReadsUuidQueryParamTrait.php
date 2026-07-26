<?php

declare(strict_types=1);

namespace App\State\Provider;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Filtres de collection portant un identifiant (`?schedulePlanId=`, `?venueId=`…).
 *
 * Ces valeurs partent telles quelles dans une comparaison DQL contre une colonne
 * PostgreSQL `uuid`/`guid` : une chaîne mal formée fait remonter
 * `invalid input syntax for type uuid` en **500** (et, sous le harnais de test,
 * avorte la transaction DAMA englobante). Une entrée client malformée doit
 * rendre **400**, pas une erreur serveur — même raison que le `#[Assert\Uuid]`
 * posé sur les DTO d'écriture (P4-22a) : la validation d'écriture ne couvre pas
 * les paramètres de LECTURE.
 */
trait ReadsUuidQueryParamTrait
{
    /** @return string|null null si le paramètre est absent ou vide */
    private function uuidQueryParam(Request $request, string $name): ?string
    {
        $value = $request->query->get($name);
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        if (!Uuid::isValid($value)) {
            throw new BadRequestHttpException(\sprintf('Le paramètre "%s" doit être un UUID.', $name));
        }

        return $value;
    }
}
