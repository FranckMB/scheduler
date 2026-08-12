<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * P2-14 — LA résolution « nom de tag → équipes de la saison ». Elle existait en TROIS
 * exemplaires : l'expansion du payload (`ScheduleConstraintBuilder::resolveTagToTeamIds`),
 * le gate pré-solve (`ValidateConstraintsController::activeTagTeamIdsByName`) et, en creux,
 * chaque nouveau consommateur à venir. Trois implémentations de la même question = la
 * dérive gate/payload que P2-14 solde ; elle ne vit plus qu'ici.
 *
 * `ResetInterface` n'est PAS décoratif : le worker Messenger garde ses services d'un
 * message à l'autre, et `ResetServicesListener` ne vide que les services tagués
 * `kernel.reset`. Sans lui, le mémo d'une génération servirait à la SUIVANTE des tags
 * figés d'avant une édition (revue #340 round 2 — trouvé en vérifiant le fix round 1).
 */
final class TeamTagResolver implements ResetInterface
{
    /** @var array<string, list<string>> mémo par MESSAGE/REQUÊTE — la sélection ET la sérialisation résolvent les mêmes tags */
    private array $memo = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * LA règle de groupement « nom de tag → équipes qui le portent » sous forme PURE — le
     * foyer côté serveur du calcul que `tagTeamIds()` fait par tag (résoudre une assignation
     * vers le nom de son tag, collecter les teamId, ignorer un tagId inconnu).
     *
     * Extrait pour la parité MÉCANIQUE avec le foyer FRONT `shared/lib/tagTeamIds.ts::buildTagTeamIds`
     * (le miroir client, utilisé par `applicableConstraints` ET `PeriodStructure`, P4-88) :
     * cas partagés `tagTeamIds.parity.json`, gardés par `TagTeamIdsMirrorParityTest`. Les
     * assignations sont supposées DÉJÀ filtrées à la saison (comme les reçoit le front) — la
     * saison n'entre pas dans le groupement, seulement dans la requête de `tagTeamIds()`.
     *
     * @param list<array{id: string, name: string}>      $tags
     * @param list<array{teamId: string, tagId: string}> $assignments
     *
     * @return array<string, list<string>> nom de tag => teamIds triés
     */
    public static function teamIdsByTagName(array $tags, array $assignments): array
    {
        $nameByTagId = [];
        foreach ($tags as $tag) {
            $nameByTagId[$tag['id']] = $tag['name'];
        }

        $byName = [];
        foreach ($assignments as $assignment) {
            $name = $nameByTagId[$assignment['tagId']] ?? null;
            if (null === $name) {
                continue; // tagId inconnu : aucune équipe, comme le NO-OP du backend
            }
            $byName[$name][] = $assignment['teamId'];
        }

        foreach ($byName as $name => $teamIds) {
            $unique = array_values(array_unique($teamIds));
            sort($unique);
            $byName[$name] = $unique;
        }

        return $byName;
    }

    public function reset(): void
    {
        $this->memo = [];
    }

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
        $memoKey = $clubId . '|' . $seasonId . '|' . $targetTag;
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }
        $tag = $this->entityManager->getRepository(TeamTag::class)->findOneBy(['name' => $targetTag, 'clubId' => $clubId]);
        if (!$tag instanceof TeamTag) {
            // Placeholders PSR-3, pas d'interpolation : un message unique par tag/club
            // casserait le regroupement/dédoublonnage des agrégateurs de logs.
            $this->logger->warning(
                'Tag \'{targetTag}\' not found for club {clubId} — constraint will be ignored.',
                ['targetTag' => $targetTag, 'clubId' => $clubId, 'seasonId' => $seasonId],
            );

            return $this->memo[$memoKey] = [];
        }

        $teamIds = [];
        foreach ($this->entityManager->getRepository(TeamTagAssignment::class)->findBy(['tagId' => $tag->getId(), 'seasonId' => $seasonId]) as $assignment) {
            $teamIds[] = $assignment->getTeamId();
        }
        sort($teamIds);

        return $this->memo[$memoKey] = $teamIds;
    }
}
