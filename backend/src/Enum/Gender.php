<?php

declare(strict_types=1);

namespace App\Enum;

enum Gender: string
{
    use HasValues;

    case M = 'M';
    case F = 'F';
    case MIXTE = 'MIXTE';
}
