<?php

declare(strict_types=1);

namespace App\Enum;

enum LockLevel: string
{
    case NONE = 'NONE';
    case SOFT = 'SOFT';
    case HARD = 'HARD';
}
