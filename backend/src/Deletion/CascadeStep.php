<?php

declare(strict_types=1);

namespace App\Deletion;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Une étape de la cascade d'une suppression : elle sait **s'exécuter** ET **se compter**.
 *
 * ⚑ Les deux dans le MÊME objet, délibérément. Avant P3-16, ce que la suppression détruisait
 * (`EntityCascadeDeleter`) et ce que l'écran annonçait (compté côté front, depuis le cache
 * react-query) étaient deux listes indépendantes — et elles avaient divergé : la modale du
 * gymnase annonçait 2 familles quand la cascade en emportait 7 et en dépointait 3, matchs
 * déjà déclarés à la fédération compris. Une liste unique rend la dérive **structurellement
 * impossible** : on ne peut plus ajouter une destruction sans ajouter son compte.
 */
interface CascadeStep
{
    /**
     * Le libellé de la ligne d'impact, ou `null` pour une étape qu'on n'annonce PAS.
     *
     * Le `null` doit rester un choix motivé (donnée interne sans sens pour le gestionnaire),
     * jamais un oubli : le test de parité liste nommément les étapes silencieuses.
     */
    public function label(): ?ImpactLabel;

    /** Combien de lignes cette étape toucherait — sans rien modifier. */
    public function count(EntityManagerInterface $entityManager, DeletionTarget $target): int;

    public function execute(EntityManagerInterface $entityManager, DeletionTarget $target): void;
}
