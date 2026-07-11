<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\AuditAction;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * RGPD — écrit le journal d'audit append-only (accountability, art. 5.2).
 *
 * INSERT DBAL direct : pas de flush() d'EntityManager (qui committerait l'état
 * dirty ambiant), pas d'entité managée à vie. BEST-EFFORT LOGUÉ : un échec
 * d'audit ne casse jamais l'opération métier qu'il trace, mais il est signalé
 * (logger error) — un audit silencieusement mort serait pire que pas d'audit.
 *
 * RÈGLE PII : jamais d'email/nom dans details — uniquement des ids et des
 * compteurs. Le rapprochement id→identité se fait via les tables sources tant
 * qu'elles existent (et devient impossible après anonymisation : voulu).
 */
final class AuditTrail
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** @param array<string, mixed> $details ids/compteurs uniquement — JAMAIS de PII */
    public function record(
        AuditAction $action,
        ?string $actorUserId = null,
        ?string $clubId = null,
        ?string $entityType = null,
        ?string $entityId = null,
        array $details = [],
    ): void {
        $connection = $this->entityManager->getConnection();
        // Best-effort RÉEL sous Postgres : un statement raté à l'intérieur
        // d'une transaction l'avorte (« current transaction is aborted ») et
        // ferait échouer l'opération métier malgré le catch. SAVEPOINT quand
        // une transaction est active → l'échec d'audit se rollback seul.
        $inTransaction = $connection->isTransactionActive();
        try {
            if ($inTransaction) {
                $connection->createSavepoint('audit_trail');
            }
            $connection->executeStatement(
                'INSERT INTO audit_log (id, occurred_at, actor_user_id, club_id, action, entity_type, entity_id, details)
                 VALUES (:id, :at, :actor, :club, :action, :etype, :eid, :details)',
                [
                    'id' => Uuid::v4()->toRfc4122(),
                    'at' => $this->clock->now()->format('Y-m-d H:i:s'),
                    'actor' => $actorUserId,
                    'club' => $clubId,
                    'action' => $action->value,
                    'etype' => $entityType,
                    'eid' => $entityId,
                    'details' => json_encode($details, \JSON_THROW_ON_ERROR),
                ],
            );
            if ($inTransaction) {
                $connection->releaseSavepoint('audit_trail');
            }
        } catch (Throwable $e) {
            if ($inTransaction) {
                try {
                    $connection->rollbackSavepoint('audit_trail');
                } catch (Throwable) {
                    // savepoint jamais créé (échec avant) — rien à défaire.
                }
            }
            $this->logger?->error('audit_trail_write_failed', [
                'action' => $action->value,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
