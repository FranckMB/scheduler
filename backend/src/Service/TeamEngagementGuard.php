<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\FixtureStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Une équipe ENGAGÉE EN COMPÉTITION est intouchable sur deux points : elle ne peut
 * être ni supprimée, ni changer de niveau.
 *
 * Réalité du terrain : valider le planning de la saison valide aussi un PÉRIMÈTRE —
 * les équipes qui jouent. Une fois les matchs envoyés à la fédération, on ne revient
 * plus dessus : « une équipe qui joue ne peut pas être supprimée ni changer de
 * niveau ; elle peut être déplacée ou changer de créneau ». Le planning de la saison
 * s'ajuste (rarement) ; le périmètre engagé, non.
 *
 * Engagée = elle porte au moins un match que le solveur n'a plus le droit d'ignorer :
 * tout `Fixture` de statut ≠ UNPLACED. Un match À L'EXTÉRIEUR compte — il naît PLACED
 * à l'import FBI parce que son horaire est imposé par l'adversaire, et l'équipe joue
 * bel et bien. UNPLACED = encore en traitement, il n'engage rien.
 *
 * Ce qui reste LIBRE, et doit le rester : le `priorityTierId` / `tierOrder` (la
 * perception que le club a de son équipe — ça bouge) et `isActive` (qui sert aux
 * plannings de période, pas au périmètre de la saison).
 *
 * `isEngaged()` et `engagedTeamIds()` répondent à la MÊME question — l'une pour la
 * garde d'écriture, l'autre pour le contrat de lecture qui l'affiche. Deux façons de
 * la poser feraient deux vérités, et deux vérités finissent toujours par diverger.
 *
 * SQL brut, comme SchedulePlanProvisioner : filter-free (l'équipe visée n'est pas
 * forcément dans la saison active) et une seule requête. RLS scope par club.
 */
final class TeamEngagementGuard
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function isEngaged(string $teamId): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM fixture WHERE team_id = :tid AND status <> :unplaced LIMIT 1',
            ['tid' => $teamId, 'unplaced' => FixtureStatus::UNPLACED->value],
        );
    }

    /** @throws ConflictHttpException si l'équipe joue déjà en compétition */
    public function assertNotEngaged(string $teamId, string $message): void
    {
        if ($this->isEngaged($teamId)) {
            throw new ConflictHttpException($message);
        }
    }

    /**
     * Les équipes engagées parmi `$teamIds`, en UNE requête — un EXISTS par ligne
     * N+1-erait la collection des équipes.
     *
     * @param list<string> $teamIds
     *
     * @return array<string, true>
     */
    public function engagedTeamIds(array $teamIds): array
    {
        if ([] === $teamIds) {
            return [];
        }

        /** @var list<string> $rows */
        $rows = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT team_id FROM fixture WHERE team_id IN (:tids) AND status <> :unplaced',
            ['tids' => $teamIds, 'unplaced' => FixtureStatus::UNPLACED->value],
            ['tids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $set = [];
        foreach ($rows as $teamId) {
            $set[$teamId] = true;
        }

        return $set;
    }
}
