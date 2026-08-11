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
                'ffbb-resync',
                'Resynchroniser depuis la FFBB',
                'Ré-importe l\'identité FFBB du club (nom, coordonnées, logo, comité/ligue) — le même ré-import que le bouton de la fiche club, déclenché par le support.',
                'app:clubs:ffbb-resync',
                dangerous: false,
            ),
            new AdminActionDefinition(
                'mark-next-season-paid',
                'Marquer la saison suivante payée',
                'Enregistre le paiement de la SAISON SUIVANTE du club (abonnement par saison, P1-5) : ouvre le gate de bascule. Idempotent — le marqueur ne recule jamais.',
                'app:clubs:mark-next-season-paid',
                dangerous: false,
            ),
            new AdminActionDefinition(
                'set-plan-decouverte',
                'Offre : Découverte',
                'Repasse le club sur l\'offre Découverte (gratuit) : périmètre complet, pool de crédits de sortie, bascule de saison fermée.',
                'app:clubs:set-plan',
                dangerous: false,
                arguments: ['--plan' => 'decouverte'],
            ),
            new AdminActionDefinition(
                'set-plan-essentiel',
                'Offre : Essentiel',
                'Attribue l\'offre Essentiel (≤ 20 équipes). L\'offre reste effective tant que la saison est réglée (« Marquer la saison suivante payée »).',
                'app:clubs:set-plan',
                dangerous: false,
                arguments: ['--plan' => 'essentiel'],
            ),
            new AdminActionDefinition(
                'set-plan-club',
                'Offre : Club',
                'Attribue l\'offre Club (≤ 30 équipes). L\'offre reste effective tant que la saison est réglée.',
                'app:clubs:set-plan',
                dangerous: false,
                arguments: ['--plan' => 'club'],
            ),
            new AdminActionDefinition(
                'set-plan-grand-club',
                'Offre : Grand club',
                'Attribue l\'offre Grand club (≤ 50 équipes). L\'offre reste effective tant que la saison est réglée.',
                'app:clubs:set-plan',
                dangerous: false,
                arguments: ['--plan' => 'grand-club'],
            ),
            new AdminActionDefinition(
                'set-plan-sans-limite',
                'Offre : Sans limite',
                'Attribue l\'offre Sans limite (aucun cap d\'équipes). L\'offre reste effective tant que la saison est réglée.',
                'app:clubs:set-plan',
                dangerous: false,
                arguments: ['--plan' => 'sans-limite'],
            ),
            new AdminActionDefinition(
                'set-plan-beta',
                'Offre : Bêta',
                'Attribue l\'offre Bêta (superadmin-only, tout illimité). L\'offre reste effective tant que la saison est réglée.',
                'app:clubs:set-plan',
                dangerous: false,
                arguments: ['--plan' => 'beta'],
            ),
            new AdminActionDefinition(
                'reset-credits',
                'Réinitialiser les crédits de sortie',
                'Remet à zéro le pool de crédits de sortie du club (offre Découverte). Le pool n\'est pas rechargeable par l\'utilisateur.',
                'app:clubs:reset-credits',
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
