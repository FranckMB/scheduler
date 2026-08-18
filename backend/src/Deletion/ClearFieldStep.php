<?php

declare(strict_types=1);

namespace App\Deletion;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Étape de **dépointage** : la ligne SURVIT, elle perd seulement sa référence à l'entité
 * supprimée (`Fixture.venueId`, `Team.forcedVenueId`, `ScheduleSlotTemplate.coachId`…).
 *
 * ⚠ Son libellé ne doit jamais dire « sera supprimé » : ce n'est pas ce qui se passe, et la
 * nuance décide du geste. Un match qui perd sa salle reste un match — il redevient « à
 * placer », donc récupérable, ce qui est précisément pourquoi la décision fondateur laisse
 * le geste passer au lieu de le refuser.
 */
final readonly class ClearFieldStep implements CascadeStep
{
    /**
     * @param class-string $entityClass
     */
    public function __construct(
        private string $entityClass,
        private string $field,
        private ?ImpactLabel $label,
    ) {}

    public function label(): ?ImpactLabel
    {
        return $this->label;
    }

    public function count(EntityManagerInterface $entityManager, DeletionTarget $target): int
    {
        $qb = $entityManager->createQueryBuilder()->select('COUNT(e.id)')->from($this->entityClass, 'e');

        return (int) $this->scope($qb, $target)->getQuery()->getSingleScalarResult();
    }

    public function execute(EntityManagerInterface $entityManager, DeletionTarget $target): void
    {
        $qb = $entityManager->createQueryBuilder()
            ->update($this->entityClass, 'e')
            ->set(\sprintf('e.%s', $this->field), 'NULL');
        $this->scope($qb, $target)->getQuery()->execute();
    }

    private function scope(QueryBuilder $qb, DeletionTarget $target): QueryBuilder
    {
        return $qb->where(\sprintf('e.%s = :value', $this->field))
            ->andWhere('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->setParameter('value', $target->id)
            ->setParameter('clubId', $target->clubId)
            ->setParameter('seasonId', $target->seasonId);
    }
}
