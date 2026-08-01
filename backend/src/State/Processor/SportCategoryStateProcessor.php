<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\SportCategoryResource;
use App\Dto\SportCategoryInput;
use App\Entity\SportCategory;

/**
 * @extends AbstractStateProcessor<SportCategory, SportCategoryInput, SportCategoryResource>
 */
class SportCategoryStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return SportCategory::class;
    }

    /**
     * @param SportCategoryInput $input
     */
    protected function createEntityFromInput(object $input): SportCategory
    {
        $entity = new SportCategory;
        if (null !== $input->sportId) {
            $entity->setSportId($input->sportId);
        }
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->isCustom) {
            $entity->setIsCustom($input->isCustom);
        }
        if (null !== $input->ageMin) {
            $entity->setAgeMin($input->ageMin);
        }
        if (null !== $input->ageMax) {
            $entity->setAgeMax($input->ageMax);
        }
        // ⚠ Une catégorie personnalisée sans `sortOrder` explicite valait 0 — le défaut de
        // l'entité — donc la MÊME valeur que « Vétéran », en tête du catalogue. Tant que
        // rien ne triait, c'était sans effet ; depuis que `SportCategoryStateProvider`
        // ordonne réellement (P4-36), elle sautait devant tout l'axe des âges dans chaque
        // sélecteur de l'application (revue #347). On l'AJOUTE donc à la fin.
        $entity->setSortOrder($input->sortOrder ?? $this->nextSortOrder());

        return $entity;
    }

    /**
     * @param SportCategory      $entity
     * @param SportCategoryInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->sportId) {
            $entity->setSportId($input->sportId);
        }
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->isCustom) {
            $entity->setIsCustom($input->isCustom);
        }
        if (null !== $input->ageMin) {
            $entity->setAgeMin($input->ageMin);
        }
        if (null !== $input->ageMax) {
            $entity->setAgeMax($input->ageMax);
        }
        if (null !== $input->sortOrder) {
            $entity->setSortOrder($input->sortOrder);
        }
    }

    /**
     * @param SportCategory $entity
     */
    protected function mapEntityToOutput(object $entity): SportCategoryResource
    {
        return SportCategoryResource::fromEntity($entity);
    }

    /** Le rang d'affichage suivant : une catégorie ajoutée se range APRÈS les existantes. */
    private function nextSortOrder(): int
    {
        $max = $this->entityManager->createQueryBuilder()
            ->select('MAX(c.sortOrder)')
            ->from(SportCategory::class, 'c')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : ((int) $max) + 1;
    }
}
