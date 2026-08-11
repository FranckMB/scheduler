<?php

declare(strict_types=1);

namespace App\AdminJob;

/**
 * One explicitly allowed SUPPORT ACTION on a single club (SA4). Manual only —
 * never scheduled. Runtime input is BOUNDED BY A CLOSED SCHEMA: the target club id
 * (always injected as `--club`) plus, when $argumentSchema is set, the enum-valued
 * arguments it declares — nothing free-text is representable by construction. An
 * action with no schema accepts NO body (allowlist stays total).
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
        /**
         * Closed schema for the RUNTIME arguments this action accepts from the
         * request (enum-valued only). null → the action takes no body.
         */
        public ?AdminActionArgumentSchema $argumentSchema = null,
    ) {}

    public function lockKey(): string
    {
        return $this->runKey ?? 'action:' . $this->key;
    }
}
