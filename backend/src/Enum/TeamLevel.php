<?php

declare(strict_types=1);

namespace App\Enum;

enum TeamLevel: string
{
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
