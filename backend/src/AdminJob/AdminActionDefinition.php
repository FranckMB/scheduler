<?php

declare(strict_types=1);

namespace App\AdminJob;

/**
 * One explicitly allowed SUPPORT ACTION on a single club (SA4). Manual only —
 * never scheduled. The only runtime parameter is the target club id, injected
 * as `--club` by the controller: the allowlist stays total (no free arguments).
 */
final readonly class AdminActionDefinition
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $command,
        /** Destructive gesture → the console requires typing the club name to confirm. */
        public bool $dangerous,
    ) {}
}
