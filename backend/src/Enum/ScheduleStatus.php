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
    /** Manager marked the plan finished → read-only (see planning-lifecycle-validated.md). */
    case VALIDATED = 'VALIDATED';
}
