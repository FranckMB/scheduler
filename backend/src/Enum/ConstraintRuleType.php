<?php

declare(strict_types=1);

namespace App\Enum;

enum ConstraintRuleType: string
{
    case HARD = 'HARD';
    case PREFERRED = 'PREFERRED';
    case BONUS = 'BONUS';
    case LOCK = 'LOCK';
}
