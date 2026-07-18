<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * État anti-spam des alertes superadmin (connexion admin, pattern AdminJobRunStore).
 * Une ligne par check : ok→firing = on notifie UNE fois ; firing→firing = silence ;
 * firing→ok = notification de rétablissement. Jamais de re-spam pendant un incident.
 */
final readonly class AdminAlertStateStore
{
    public function __construct(private ManagerRegistry $registry) {}

    /**
     * Applique le nouvel état observé et rend le DIFF à notifier.
     *
     * @param list<array{key: string, message: string}> $alerts les checks actuellement au ROUGE
     *
     * @return array{fired: list<array{key: string, message: string}>, recovered: list<string>}
     */
    public function transition(array $alerts): array
    {
        $connection = $this->connection();
        $firingNow = array_column($alerts, null, 'key');

        /** @var array<string, string> $previous check_key => status */
        $previous = array_column(
            $connection->fetchAllAssociative('SELECT check_key, status FROM admin_alert_state'),
            'status',
            'check_key',
        );

        $fired = [];
        foreach ($firingNow as $key => $alert) {
            if ('firing' !== ($previous[$key] ?? 'ok')) {
                $fired[] = $alert;
            }
            $connection->executeStatement(
                'INSERT INTO admin_alert_state (check_key, status, updated_at, last_alerted_at) VALUES (:key, \'firing\', NOW(), NOW())
                 ON CONFLICT (check_key) DO UPDATE SET status = \'firing\', updated_at = NOW(), last_alerted_at = CASE WHEN admin_alert_state.status = \'firing\' THEN admin_alert_state.last_alerted_at ELSE NOW() END',
                ['key' => $key],
            );
        }

        $recovered = [];
        foreach ($previous as $key => $status) {
            if ('firing' === $status && !isset($firingNow[$key])) {
                $recovered[] = (string) $key;
                $connection->executeStatement(
                    'UPDATE admin_alert_state SET status = \'ok\', updated_at = NOW() WHERE check_key = :key',
                    ['key' => $key],
                );
            }
        }

        return ['fired' => $fired, 'recovered' => $recovered];
    }

    private function connection(): Connection
    {
        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
