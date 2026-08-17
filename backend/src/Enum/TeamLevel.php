<?php

declare(strict_types=1);

namespace App\Enum;

enum TeamLevel: string
{
    use HasValues;

    /**
     * Libellé d'affichage français. Le back le fournit pour que le front n'invente
     * aucun vocabulaire métier (.claude/rules/frontend.md) : les stats
     * d'utilisation des gymnases (P3-22) le sérialisent tel quel dans `byLevel`.
     * Doit rester aligné avec `frontend/src/features/wizard/lib/labels.ts`.
     */
    public function label(): string
    {
        return match ($this) {
            self::ELITE => 'Élite',
            self::REGIONAL => 'Régional',
            self::NATIONAL => 'National',
            self::DEPARTEMENTAL => 'Départemental',
            self::LOISIR_ADULTE => 'Loisir adulte',
            self::LOISIR_JEUNE => 'Loisir jeune',
            self::HONNEUR => 'Honneur',
            self::PROMOTION => 'Promotion',
            self::PRE_REGION => 'Pré-région',
        };
    }

    case ELITE = 'ELITE';
    case REGIONAL = 'REGIONAL';
    case NATIONAL = 'NATIONAL';
    case DEPARTEMENTAL = 'DEPARTEMENTAL';
    case LOISIR_ADULTE = 'LOISIR_ADULTE';
    case LOISIR_JEUNE = 'LOISIR_JEUNE';
    case HONNEUR = 'HONNEUR';
    case PROMOTION = 'PROMOTION';
    case PRE_REGION = 'PRE_REGION';
}
