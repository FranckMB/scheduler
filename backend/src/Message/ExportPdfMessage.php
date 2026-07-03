<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ExportPdfMessage
{
    public function __construct(
        private string $scheduleId,
        // RLS: the worker has no HTTP request, so no tenant GUC is set. The
        // handler needs the club id up-front to scope its own connection —
        // it cannot read the schedule to discover it (RLS would return nothing).
        private string $clubId,
    ) {}

    public function getScheduleId(): string
    {
        return $this->scheduleId;
    }

    public function getClubId(): string
    {
        return $this->clubId;
    }
}
