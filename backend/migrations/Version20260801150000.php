<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Revue #347 — pousse les catégories PERSONNALISÉES derrière le bloc standard.
 *
 * `Version20260801120000` renumérotait les douze catégories standard et épargnait
 * délibérément les personnalisées, « leur ordre appartenant au club ». C'était vrai de leur
 * ordre RELATIF, faux de leur place ABSOLUE : une catégorie personnalisée est créée sans
 * `sortOrder`, donc à 0 — la valeur même que la renumérotation attribue à « Vétéran ».
 *
 * Tant que rien ne triait sur cette colonne, cela ne se voyait pas. Depuis que
 * `SportCategoryStateProvider` ordonne réellement (P4-36), ces catégories sautaient en tête
 * de TOUS les sélecteurs — wizard, matchs, planning — devant Senior et devant tout l'axe des
 * âges. Le protectionnisme produisait exactement le désordre que le lot devait supprimer.
 *
 * Une migration SÉPARÉE plutôt qu'un correctif dans la précédente : celle-ci est déjà
 * appliquée sur les environnements existants, et modifier un `up()` joué ne rejoue rien.
 *
 * L'ordre relatif des personnalisées est conservé (translation uniforme). Les nouvelles
 * sont désormais ajoutées à la fin par `SportCategoryStateProcessor::nextSortOrder`.
 */
final class Version20260801150000 extends AbstractMigration
{
    /** Nombre de catégories standard — le bloc derrière lequel les personnalisées se rangent. */
    private const STANDARD_COUNT = 12;

    public function getDescription(): string
    {
        return 'Revue #347: range les catégories personnalisées après les 12 standard';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE sport_category SET sort_order = sort_order + :offset WHERE is_custom = true', ['offset' => self::STANDARD_COUNT]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE sport_category SET sort_order = GREATEST(sort_order - :offset, 0) WHERE is_custom = true', ['offset' => self::STANDARD_COUNT]);
    }
}
