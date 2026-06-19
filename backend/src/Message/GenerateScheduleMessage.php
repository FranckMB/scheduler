<?php

declare(strict_types=1);

namespace App\Message;

final readonly class GenerateScheduleMessage
{
    public function __construct(
        private string $scheduleId,
        private string $clubId,
        private int $timeoutSeconds = 650,
    ) {}

    public function getScheduleId(): string
    {
        return $this->scheduleId;
    }

    public function getClubId(): string
    {
        return $this->clubId;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function getClubRoutingKey(): string
    {
        return 'club_id:' . $this->clubId;
    }
}
