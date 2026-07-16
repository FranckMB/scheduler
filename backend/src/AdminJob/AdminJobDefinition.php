<?php

declare(strict_types=1);

namespace App\AdminJob;

/** One explicitly allowed operational command and its fixed safe arguments. */
final readonly class AdminJobDefinition
{
    /** @param array<string, bool|int|string> $arguments */
    public function __construct(
        public string $key,
        public string $label,
        public string $command,
        public AdminJobSchedule $schedule,
        public array $arguments = [],
        public bool $manualTriggerAllowed = false,
    ) {}
}
