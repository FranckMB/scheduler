<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Évaluateur PUR des conditions d'alerte superadmin : tableaux en entrée, liste
 * d'alertes en sortie — aucune I/O, unit-testable à sec. Consommé par
 * app:health:alert, qui gère l'état (anti-spam) et l'envoi.
 *
 * Chaque alerte = {key, message}. La key est STABLE (c'est la clé d'état
 * admin_alert_state) : en changer une réarme l'alerte correspondante.
 */
final readonly class HealthAlertEvaluator
{
    /** Miroir du seuil d'avertissement du dashboard (AdminHealthService). */
    private const BACKLOG_THRESHOLD = 100;

    /** INFEASIBLE : > 50 % sur 24 h, avec un PLANCHER de volume — jamais d'alerte à vide. */
    private const INFEASIBLE_RATE_THRESHOLD = 0.5;
    private const INFEASIBLE_MIN_GENERATIONS = 5;

    /**
     * @param array<string, mixed>                                                                              $health    sortie d'AdminHealthService::health()
     * @param list<array{key: string, label: string, lastUpdatedAt: ?string, staleAfterDays: int, stale: bool}> $freshness
     * @param array{generations24h: int, infeasible24h: int}                                                    $solver
     *
     * @return list<array{key: string, message: string}>
     */
    public function evaluate(array $health, array $freshness, array $solver): array
    {
        $alerts = [];

        $services = \is_array($health['services'] ?? null) ? $health['services'] : [];
        foreach (['database', 'redis', 'engine', 'mercure', 'worker'] as $service) {
            $status = \is_array($services[$service] ?? null) ? ($services[$service]['status'] ?? 'unknown') : 'unknown';
            if ('up' !== $status) {
                $alerts[] = ['key' => 'service:' . $service, 'message' => \sprintf('Service « %s » indisponible (statut : %s).', $service, \is_string($status) ? $status : 'unknown')];
            }
        }

        $messenger = \is_array($health['messenger'] ?? null) ? $health['messenger'] : [];
        $backlog = \is_int($messenger['backlog'] ?? null) ? $messenger['backlog'] : 0;
        if ($backlog > self::BACKLOG_THRESHOLD) {
            $alerts[] = ['key' => 'messenger-backlog', 'message' => \sprintf('File Messenger en retard : %d messages en attente (seuil %d) — les générations s\'empilent.', $backlog, self::BACKLOG_THRESHOLD)];
        }
        $failed = \is_int($messenger['failed'] ?? null) ? $messenger['failed'] : 0;
        if ($failed > 0) {
            $alerts[] = ['key' => 'messenger-failed', 'message' => \sprintf('%d message(s) en file d\'échec Messenger — des générations ont définitivement échoué.', $failed)];
        }

        if ($solver['generations24h'] >= self::INFEASIBLE_MIN_GENERATIONS
            && $solver['infeasible24h'] / max(1, $solver['generations24h']) > self::INFEASIBLE_RATE_THRESHOLD) {
            $alerts[] = ['key' => 'infeasible-rate', 'message' => \sprintf('Taux INFEASIBLE anormal : %d/%d générations sur 24 h — un bug ou une donnée corrompue frappe peut-être plusieurs clubs.', $solver['infeasible24h'], $solver['generations24h'])];
        }

        foreach ($freshness as $referential) {
            if ($referential['stale']) {
                $alerts[] = ['key' => 'freshness:' . $referential['key'], 'message' => \sprintf('Référentiel « %s » périmé (dernière mise à jour : %s, seuil %d j) — l\'import automatique est peut-être mort en silence.', $referential['label'], $referential['lastUpdatedAt'] ?? 'jamais', $referential['staleAfterDays'])];
            }
        }

        return $alerts;
    }
}
