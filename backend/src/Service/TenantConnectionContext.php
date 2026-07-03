<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Sets the PostgreSQL tenant GUC (app.club_id) the RLS policies read.
 *
 * Session-scoped set_config(..., false), NOT `SET LOCAL`: the previous
 * `SET LOCAL` ran outside any transaction, which PostgreSQL silently ignores —
 * the GUC was never actually set in production (audit SEC-03). Session scope is
 * safe here because PHP-FPM uses one dedicated connection per request (no
 * pooling) and the messenger worker sets/clears the GUC around each message.
 *
 * ⚠ Incompatible with a transaction-pooling proxy (pgbouncer): a pooled
 * connection would leak the GUC across tenants. Revisit before adding one.
 */
final readonly class TenantConnectionContext
{
    public function __construct(private Connection $connection) {}

    public function setClubId(string $clubId): void
    {
        // Parameterised — replaces the string-concatenated SET LOCAL.
        $this->connection->executeStatement(
            'SELECT set_config(\'app.club_id\', ?, false)',
            [$clubId],
        );
    }

    public function clear(): void
    {
        // A connection that was never opened (or died) has no session GUC to
        // leak: skip the round-trip on fresh connections (every plain HTTP
        // request pays this otherwise), and never let a dead connection turn
        // the cleanup into a new exception that would mask the real failure
        // (worker finally blocks after a 650 s solve).
        if (!$this->connection->isConnected()) {
            return;
        }

        try {
            $this->connection->executeStatement(
                'SELECT set_config(\'app.club_id\', \'\', false)',
            );
        } catch (Throwable) {
            // Connection is unusable → its session (and GUC) is gone anyway.
        }
    }
}
