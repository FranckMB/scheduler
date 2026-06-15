<?php

declare(strict_types=1);

namespace App\Enum;

enum Gender: string
{
    case M = 'M';
    case F = 'F';
    case MIXTE = 'MIXTE';
}
