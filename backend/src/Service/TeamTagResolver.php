<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * P2-14 — LA résolution « nom de tag → équipes de la saison ». Elle existait en TROIS
 * exemplaires : l'expansion du payload (`ScheduleConstraintBuilder::resolveTagToTeamIds`),
 * le gate pré-solve (`ValidateConstraintsController::activeTagTeamIdsByName`) et, en creux,
 * chaque nouveau consommateur à venir. Trois implémentations de la même question = la
 * dérive gate/payload que P2-14 solde ; elle ne vit plus qu'ici.
 */
final class TeamTagResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Les équipes portant ce tag dans la saison, triées par id. Le TRI fait partie du
     * contrat : cette liste ordonne l'expansion par équipe d'une contrainte CLUB+targetTag,
     * donc l'ordre des lignes `constraints` du payload — et le hash calculé dessus. Le
     * `teamId` est STABLE (une équipe n'est pas recréée), contrairement à l'id d'assignation.
     *
     * Tag inconnu → liste vide + warning : la contrainte ne produira aucune ligne, et le
     * silence est exactement ce que la série ENGINE a soldé.
     *
     * @return list<string>
     */
    public function tagTeamIds(string $targetTag, string $seasonId, string $clubId): array
    {
        $tag = $this->entityManager->getRepository(TeamTag::class)->findOneBy(['name' => $targetTag, 'clubId' => $clubId]);
        if (!$tag instanceof TeamTag) {
            $this->logger->warning(
                "Tag '{$targetTag}' not found for club {$clubId} — constraint will be ignored.",
                ['targetTag' => $targetTag, 'clubId' => $clubId, 'seasonId' => $seasonId],
            );

            return [];
        }

        $teamIds = [];
        foreach ($this->entityManager->getRepository(TeamTagAssignment::class)->findBy(['tagId' => $tag->getId(), 'seasonId' => $seasonId]) as $assignment) {
            $teamIds[] = $assignment->getTeamId();
        }
        sort($teamIds);

        return $teamIds;
    }
}
