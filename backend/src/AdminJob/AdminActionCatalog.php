<?php

declare(strict_types=1);

namespace App\AdminJob;

/**
 * Closed catalog of club support actions (SA4) — the "act without building a
 * feature" lever. Adding an action = one entry here once the CLI command
 * exists. NEVER accept a command or arguments from the request: this list is
 * the entire allowlist (same doctrine as AdminJobCatalog, SA3).
 *
 * Suspension / approbation fallback : volontairement ABSENTES (décision
 * fondateur 2026-07-18) — différées au premier cas réel, leur métier n'est
 * pas tranché (effet exact d'une suspension, périmètre du fallback).
 */
final readonly class AdminActionCatalog
{
    /** @return list<AdminActionDefinition> */
    public function all(): array
    {
        return [
            new AdminActionDefinition(
                'reset-generation-quota',
                'Réinitialiser le quota de générations',
                'Remet le compteur de générations de la saison à zéro (offre Découverte).',
                'app:clubs:reset-quota',
                dangerous: false,
            ),
            new AdminActionDefinition(
                'reset-current-season',
                'Réinitialiser la saison courante',
                'Vide toutes les données de la saison courante du club (structure, calendrier, plannings). La saison et le club survivent — le club repart au wizard.',
                'app:clubs:reset-season',
                dangerous: true,
            ),
            new AdminActionDefinition(
                'purge-old-seasons',
                'Purger les anciennes saisons',
                'Supprime les saisons au-delà de la rétention (N-2 et plus anciennes) de ce club.',
                'app:seasons:purge',
                dangerous: true,
                // Geste humain explicite : la grâce post-pivot du cron ne s'applique pas
                // (sinon l'action confirmée serait un no-op silencieux tout l'été).
                arguments: ['--no-grace' => true],
                // Même clé de verrou/historique que le job planifié : le geste manuel et
                // le cron de 03:00 balaient les mêmes tables — ils DOIVENT se sérialiser.
                runKey: 'purge-seasons',
            ),
        ];
    }

    public function find(string $key): ?AdminActionDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }
}
