<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Les 4 règles implicites « bien-être » RÉGLABLES par club/saison (contrat moteur 2.7).
 *
 * La valeur de chaque cas EST la clé camelCase du bloc `implicitRules` du payload — c'est
 * elle que le moteur lit (aliases Pydantic `coachRestDay`/`salarieDistribution`/
 * `maxConsecutiveSessions`/`ageAscending`) et elle que porte le diagnostic
 * `implicit_rule_not_honored` (`ruleKey`). Aucun mapping intermédiaire : la clé stockée est
 * la clé émise.
 *
 * Deux des quatre règles portent un SEUIL réglable (borné côté moteur : `minRestDays` 1-4,
 * `maxConsecutive` 2-6). Les deux autres n'ont qu'un cran d'intensité. `paramKey()` dit
 * laquelle (null = pas de seuil), et `paramDefault()`/`paramMin()`/`paramMax()` portent les
 * bornes du contrat — la source unique côté backend de ce que le moteur accepte.
 */
enum ImplicitRuleKey: string
{
    use HasValues;

    /** La clé du seuil réglable dans `params`, ou null pour une règle à intensité seule. */
    public function paramKey(): ?string
    {
        return match ($this) {
            self::COACH_REST_DAY => 'minRestDays',
            self::MAX_CONSECUTIVE_SESSIONS => 'maxConsecutive',
            self::SALARIE_DISTRIBUTION, self::AGE_ASCENDING => null,
        };
    }

    /** Le seuil par défaut (défaut moteur), ou null si la règle n'a pas de seuil. */
    public function paramDefault(): ?int
    {
        return match ($this) {
            self::COACH_REST_DAY => 1,
            self::MAX_CONSECUTIVE_SESSIONS => 3,
            self::SALARIE_DISTRIBUTION, self::AGE_ASCENDING => null,
        };
    }

    /** Borne basse acceptée par le moteur (incluse), ou null si pas de seuil. */
    public function paramMin(): ?int
    {
        return match ($this) {
            self::COACH_REST_DAY => 1,
            self::MAX_CONSECUTIVE_SESSIONS => 2,
            self::SALARIE_DISTRIBUTION, self::AGE_ASCENDING => null,
        };
    }

    /** Borne haute acceptée par le moteur (incluse), ou null si pas de seuil. */
    public function paramMax(): ?int
    {
        return match ($this) {
            self::COACH_REST_DAY => 4,
            self::MAX_CONSECUTIVE_SESSIONS => 6,
            self::SALARIE_DISTRIBUTION, self::AGE_ASCENDING => null,
        };
    }

    case COACH_REST_DAY = 'coachRestDay';
    case SALARIE_DISTRIBUTION = 'salarieDistribution';
    case MAX_CONSECUTIVE_SESSIONS = 'maxConsecutiveSessions';
    case AGE_ASCENDING = 'ageAscending';
}
