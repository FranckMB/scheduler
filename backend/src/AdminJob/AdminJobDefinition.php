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
        /**
         * SA4 : clé du VERROU anti-chevauchement quand elle diffère de la clé
         * d'HISTORIQUE (`key`). Une action manuelle qui exécute la même commande
         * qu'un job planifié (purge-seasons) doit se SÉRIALISER avec lui (même
         * verrou) SANS écraser son latestRun au panneau jobs (historique séparé,
         * revue SA4 round 2). null → verrou = `key`.
         */
        public ?string $lockKey = null,
    ) {}

    public function effectiveLockKey(): string
    {
        return $this->lockKey ?? $this->key;
    }
}
