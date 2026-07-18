<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * État anti-spam des alertes superadmin (connexion admin, pattern AdminJobRunStore).
 * Une ligne par check : ok→firing = on notifie UNE fois ; firing→firing = silence ;
 * firing→ok = notification de rétablissement.
 *
 * API en DEUX temps (revue #257, finding 0) : `preview()` calcule le diff SANS
 * écrire, l'appelant ENVOIE l'email, puis `commit()` persiste. Un mailer en panne
 * laisse donc l'état intact → le prochain tick RE-TENTE l'envoi, l'alerte n'est
 * jamais perdue. (L'inverse — persister avant d'envoyer — transformait un hoquet
 * SMTP en silence permanent pour tout l'incident.)
 */
final readonly class AdminAlertStateStore
{
    public function __construct(private ManagerRegistry $registry) {}

    /**
     * Le DIFF à notifier si `$alerts` devenait l'état courant — AUCUNE écriture.
     *
     * @param list<array{key: string, message: string}> $alerts les checks actuellement au ROUGE
     *
     * @return array{fired: list<array{key: string, message: string}>, recovered: list<string>}
     */
    public function preview(array $alerts): array
    {
        $firingNow = array_column($alerts, null, 'key');
        $previous = $this->previousStates();

        $fired = [];
        foreach ($firingNow as $key => $alert) {
            if ('firing' !== ($previous[$key] ?? 'ok')) {
                $fired[] = $alert;
            }
        }

        $recovered = [];
        foreach ($previous as $key => $status) {
            if ('firing' === $status && !isset($firingNow[$key])) {
                $recovered[] = (string) $key;
            }
        }

        return ['fired' => $fired, 'recovered' => $recovered];
    }

    /**
     * Persiste `$alerts` comme nouvel état courant — APRÈS l'envoi réussi.
     * Transactionnel : un run manuel concurrent du tick cron ne peut pas laisser
     * un état à moitié écrit (revue #257, finding 9).
     *
     * @param list<array{key: string, message: string}> $alerts
     */
    public function commit(array $alerts): void
    {
        $connection = $this->connection();
        $connection->transactional(function (Connection $connection) use ($alerts): void {
            $firingNow = array_column($alerts, null, 'key');

            foreach (array_keys($firingNow) as $key) {
                $connection->executeStatement(
                    'INSERT INTO admin_alert_state (check_key, status, updated_at, last_alerted_at) VALUES (:key, \'firing\', NOW(), NOW())
                     ON CONFLICT (check_key) DO UPDATE SET status = \'firing\', updated_at = NOW(), last_alerted_at = CASE WHEN admin_alert_state.status = \'firing\' THEN admin_alert_state.last_alerted_at ELSE NOW() END',
                    ['key' => $key],
                );
            }

            foreach ($this->previousStates($connection) as $key => $status) {
                if ('firing' === $status && !isset($firingNow[$key])) {
                    $connection->executeStatement(
                        'UPDATE admin_alert_state SET status = \'ok\', updated_at = NOW() WHERE check_key = :key',
                        ['key' => $key],
                    );
                }
            }
        });
    }

    /** @return array<string, string> check_key => status */
    private function previousStates(?Connection $connection = null): array
    {
        return array_column(
            ($connection ?? $this->connection())->fetchAllAssociative('SELECT check_key, status FROM admin_alert_state'),
            'status',
            'check_key',
        );
    }

    private function connection(): Connection
    {
        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
