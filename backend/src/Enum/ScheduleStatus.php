<?php

declare(strict_types=1);

namespace App\Enum;

enum ScheduleStatus: string
{
    case DRAFT = 'DRAFT';
    case PENDING = 'PENDING';
    case GENERATING = 'GENERATING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
}
