<?php

declare(strict_types=1);

namespace App\Enum;

enum TeamLevel: string
{
    case ELITE = 'ELITE';
    case REGIONAL = 'REGIONAL';
    case NATIONAL = 'NATIONAL';
    case DEPARTEMENTAL = 'DEPARTEMENTAL';
    case LOISIR = 'LOISIR';
    case HONNEUR = 'HONNEUR';
    case PROMOTION = 'PROMOTION';
    case PRE_REGION = 'PRE_REGION';
}
