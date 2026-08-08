<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Le statut d'une saison — typé (audit D-07, 2026-08-09).
 *
 * C'était la **seule string libre** parmi les statuts du projet : `ScheduleStatus`,
 * `FixtureStatus`, `CalendarEntryStatus`, `ClubCreationRequestStatus` sont tous des enums,
 * `Season::$status` était un `string` avec ses valeurs recopiées dans un `Assert\Choice`
 * (`SeasonInput`) et dans un `in_array` (`CoachWishSeasonGuard`).
 *
 * ⚑ **Ce que le statut ne décide PAS.** La saison courante se résout au CALENDRIER
 * (`SeasonResolver`, pivot du 15 juillet), pas au statut — `SeasonTransitionService` le dit :
 * le statut est de la « display metadata ». Un statut qui dérive ne change donc pas la saison
 * qu'on lit ; il change **ce que `CoachWishSeasonGuard` fige** (`archived`/`closed` → campagne
 * de vœux en lecture seule). C'est là, et seulement là, qu'une valeur inconnue coûte quelque
 * chose : elle retombe en « modifiable » sans que rien ne le signale.
 *
 * ⚠ `ARCHIVED` et `CLOSED` sont déclarés mais **jamais posés par le code** (vérifié en base
 * le 2026-08-09 : 15 `draft`, 52 `active`, zéro des deux autres). Ils ne sont pas morts pour
 * autant — `CoachWishSeasonGuard` les LIT, et l'archivage est décrit comme manuel. Les
 * retirer fermerait la porte à laquelle le garde répond.
 */
enum SeasonStatus: string
{
    use HasValues;

    /** Les statuts qui figent une saison : plus d'écriture sur ses campagnes de vœux. */
    public function isFrozen(): bool
    {
        return self::ARCHIVED === $this || self::CLOSED === $this;
    }

    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
    case CLOSED = 'closed';
}
