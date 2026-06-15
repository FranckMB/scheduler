<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ExportPdfMessage
{
    public function __construct(
        private string $scheduleId,
    ) {}

    public function getScheduleId(): string
    {
        return $this->scheduleId;
    }
}
