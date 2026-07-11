<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RGPD — portabilité (art. 20) : export machine-readable des données.
 *
 * Deux périmètres :
 * - exportUser : les données du COMPTE (responsable de traitement = nous) —
 *   identité + memberships. JAMAIS le hash de mot de passe.
 * - exportClub : le WORKSPACE complet du club (nous = sous-traitant, le club
 *   exerce la portabilité pour ses propres données) — lignes brutes par table,
 *   sélectionnées par club_id/season_id sous le GUC RLS du club courant (posé
 *   par le TenantFilterListener) : la frontière tenant est garantie par la DB
 *   même si une requête oubliait son WHERE.
 *
 * Le format est volontairement BRUT (une clé par table, lignes associatives) :
 * complet, stable, réimportable — pas une projection d'API qui rotirait.
 */
final class RgpdExportService
{
    /**
     * Tables club-scoped exportées telles quelles (SELECT * WHERE club_id).
     * schedule est traité à part (colonnes lourdes exclues).
     */
    private const CLUB_TABLES = [
        'season',
        'team',
        'coach',
        'venue',
        'venue_training_slot',
        'constraint',
        'reservation',
        'schedule_slot_template',
        'schedule_diagnostic',
        'schedule_structure_snapshot',
        'calendar_entry',
        'competition',
        'fixture',
        'team_coach',
        'coach_player_membership',
        'team_tag',
        'sport_category',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /** @return array<string, mixed> */
    public function exportUser(User $user): array
    {
        $memberships = $this->connection()->fetchAllAssociative(
            'SELECT cu.club_id, c.name AS club_name, cu.role, cu.is_active, cu.joined_at, cu.created_at
             FROM club_user cu JOIN club c ON c.id = cu.club_id
             WHERE cu.user_id = :uid',
            ['uid' => $user->getId()],
        );

        return [
            'exportedAt' => date('c'),
            'kind' => 'user',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'createdAt' => $user->getCreatedAt()->format('c'),
                'emailVerifiedAt' => $user->getEmailVerifiedAt()?->format('c'),
            ],
            'memberships' => $memberships,
        ];
    }

    /** @return array<string, mixed> */
    public function exportClub(string $clubId): array
    {
        $connection = $this->connection();
        $data = [
            'exportedAt' => date('c'),
            'kind' => 'club',
            'club' => $connection->fetchAssociative('SELECT * FROM club WHERE id = :cid', ['cid' => $clubId]) ?: null,
        ];

        foreach (self::CLUB_TABLES as $table) {
            // Noms de table issus de la constante (jamais d'input) — le quote
            // gère le mot réservé `constraint`.
            $quoted = '"' . $table . '"';
            $data[$table] = $connection->fetchAllAssociative(
                \sprintf('SELECT * FROM %s WHERE club_id = :cid', $quoted),
                ['cid' => $clubId],
            );
        }

        // schedule sans son blob interne : SELECT * puis unset — une liste de
        // colonnes rotirait à chaque migration (revue PR-2), le blob exclu est
        // le seul invariant (snapshot_data = duplicat technique de l'export).
        $data['schedule'] = array_map(
            static function (array $row): array {
                unset($row['snapshot_data']);

                return $row;
            },
            $connection->fetchAllAssociative('SELECT * FROM schedule WHERE club_id = :cid', ['cid' => $clubId]),
        );

        // constraint_conflict n'a pas de club_id : jointure par schedule.
        $data['constraint_conflict'] = $connection->fetchAllAssociative(
            'SELECT cc.* FROM constraint_conflict cc
             JOIN schedule s ON s.id = cc.schedule_id WHERE s.club_id = :cid',
            ['cid' => $clubId],
        );

        // team_tag_assignment n'a pas de club_id : jointure par saison.
        $data['team_tag_assignment'] = $connection->fetchAllAssociative(
            'SELECT tta.* FROM team_tag_assignment tta
             JOIN season s ON s.id = tta.season_id WHERE s.club_id = :cid',
            ['cid' => $clubId],
        );

        // Memberships du club (qui a accès) — sans données de compte au-delà
        // de l'email (les comptes appartiennent à leurs titulaires).
        $data['members'] = $connection->fetchAllAssociative(
            'SELECT cu.user_id, u.email, cu.role, cu.is_active, cu.joined_at
             FROM club_user cu JOIN app_user u ON u.id = cu.user_id
             WHERE cu.club_id = :cid',
            ['cid' => $clubId],
        );

        return $data;
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
