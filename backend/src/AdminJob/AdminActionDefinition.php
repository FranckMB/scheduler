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
    /** @param array<string, bool|int|string> $arguments Fixed extra arguments from the catalog (never from the request). */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $command,
        /** Destructive gesture → the console requires typing the club name to confirm. */
        public bool $dangerous,
        public array $arguments = [],
        /**
         * Lock/history key. MUST equal the JOB catalog key when the command is
         * shared with a scheduled job (e.g. app:seasons:purge) — otherwise the
         * advisory lock would not serialize the manual gesture against the cron
         * walking the same tables (revue SA4, finding 3). null → "action:{key}".
         */
        public ?string $runKey = null,
    ) {}

    public function lockKey(): string
    {
        return $this->runKey ?? 'action:' . $this->key;
    }
}
