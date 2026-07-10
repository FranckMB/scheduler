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
    /**
     * Sibling season-plan version set aside when another version was validated
     * (specs/evolution/planning-versions.md). SERVER-SET ONLY — never accepted
     * from a client payload. Hidden from the version selector; purged with the
     * season (SeasonDataPurger). Never resurrected by reopen.
     */
    case ARCHIVED = 'ARCHIVED';
}
