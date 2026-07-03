<?php

declare(strict_types=1);

namespace App\Enum;

enum TeamLevel: string
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
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
