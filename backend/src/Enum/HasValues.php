<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Les valeurs d'un enum adossé à une chaîne, en une implémentation.
 *
 * ⚑ Elle était recopiée **cinq fois à l'identique** (`CompetitionType`, `FixtureHomeAway`,
 * `FixtureStatus`, `TeamLevel`, `TeamLinkType`) — une duplication dans la solution à la
 * duplication : `values()` n'existe que pour éviter de réécrire les valeurs dans un
 * `Assert\Choice`, et elle-même se réécrivait à chaque enum (audit D-06, 2026-08-08).
 *
 * À quoi elle sert, concrètement : un DTO écrit
 * `#[Assert\Choice(callback: [MonEnum::class, 'values'])]` au lieu de recopier la liste.
 * Le jour où l'enum gagne un cas, la validation le connaît **sans intervention** — alors
 * qu'une liste en dur le refuse en 422, silencieusement, en rendant la nouvelle valeur
 * inatteignable par l'API.
 *
 * Gardé par `EnumChoicesAreDerivedTest` : un `Assert\Choice` dont les valeurs coïncident
 * exactement avec un enum du projet doit passer par le `callback`, jamais par une copie.
 */
trait HasValues
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
