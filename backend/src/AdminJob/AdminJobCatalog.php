<?php

declare(strict_types=1);

namespace App\AdminJob;

use LogicException;

/** Closed allowlist: neither the CLI nor the future admin API accepts a raw command name. */
final class AdminJobCatalog
{
    /** @var array<string, AdminJobDefinition> */
    private array $definitions = [];

    /** @param list<AdminJobDefinition>|null $definitions Test-only override; production uses the closed defaults. */
    public function __construct(?array $definitions = null)
    {
        foreach ($definitions ?? $this->defaults() as $definition) {
            if (isset($this->definitions[$definition->key])) {
                throw new LogicException(\sprintf('Duplicate admin job key "%s".', $definition->key));
            }
            $this->definitions[$definition->key] = $definition;
        }
    }

    public function find(string $key): ?AdminJobDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /** @return list<AdminJobDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /** @return list<AdminJobDefinition> */
    private function defaults(): array
    {
        return [
            new AdminJobDefinition('reconcile-stuck-schedules', 'Réconciliation des générations bloquées', 'app:schedules:reconcile-stuck', AdminJobSchedule::everyTenMinutes(), ['--older-than' => 60]),
            new AdminJobDefinition('period-reminders', 'Rappels de périodes', 'app:periods:remind', AdminJobSchedule::daily(8)),
            new AdminJobDefinition('transition-reminders', 'Rappels de transition de saison', 'app:seasons:remind-transition', AdminJobSchedule::daily(8)),
            new AdminJobDefinition('purge-unverified-users', 'Purge des comptes non vérifiés', 'app:users:purge-unverified', AdminJobSchedule::daily(2)),
            new AdminJobDefinition('purge-erased-clubs', 'Purge des clubs effacés', 'app:clubs:purge-erased', AdminJobSchedule::daily(2, 15)),
            new AdminJobDefinition('purge-inactive-users', 'Purge des comptes inactifs', 'app:users:purge-inactive', AdminJobSchedule::daily(2, 30)),
            new AdminJobDefinition('purge-seasons', 'Purge des anciennes saisons', 'app:seasons:purge', AdminJobSchedule::daily(3)),
            new AdminJobDefinition('purge-audit-log', 'Purge du journal d’audit', 'app:audit:purge', AdminJobSchedule::daily(3, 30)),
            new AdminJobDefinition('import-school-holidays', 'Import des vacances scolaires', 'app:school-holidays:import', AdminJobSchedule::quarterly(4)),
            new AdminJobDefinition('import-public-holidays', 'Import des jours fériés', 'app:public-holidays:import', AdminJobSchedule::quarterly(4, 30)),
        ];
    }
}
