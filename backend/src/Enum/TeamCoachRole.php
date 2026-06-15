<?php

declare(strict_types=1);

namespace App\Enum;

enum TeamCoachRole: string
{
    case MAIN = 'MAIN';
    case ASSISTANT = 'ASSISTANT';
}
