<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-36 — renumérote les catégories STANDARD des clubs existants, du plus âgé au plus jeune.
 *
 * Retour terrain (2026-07-31) : « je veux Vétéran → Senior → U21 → … → U5 ». Le catalogue
 * (`CategoryCatalog`) est réordonné dans le même lot, mais il ne sert qu'à la CRÉATION d'un
 * club : chaque club reçoit sa propre COPIE des douze catégories au moment de l'inscription
 * (`AuthController::verifyEmail`). Sans cette migration, les clubs déjà créés — dont celui
 * du fondateur — garderaient l'ancienne numérotation.
 *
 * ⚠ Cette renumérotation ne se VOIT que parce que le même lot fait enfin trier le serveur
 * dessus (`SportCategoryStateProvider`). Avant lui, la collection sortait dans l'ordre des
 * UUID v4, c'est-à-dire au hasard : `sort_order` était une colonne morte.
 *
 * Les catégories PERSONNALISÉES (`is_custom = true`) ne sont pas touchées : leur ordre
 * appartient au club qui les a créées, et rien ne dit où il voudrait les voir tomber.
 *
 * Passe par la connexion `clubscheduler` (porte admin, hors RLS) : elle voit donc bien les
 * lignes de TOUS les clubs, ce qu'un `app_user` sous `FORCE ROW LEVEL SECURITY` ne pourrait
 * pas faire.
 */
final class Version20260801120000 extends AbstractMigration
{
    /** Ordre d'affichage retenu — miroir exact de `CategoryCatalog::categories()`. */
    private const ORDER = [
        'Vétéran' => 0,
        'Senior' => 1,
        'U21' => 2,
        'U18' => 3,
        'U15' => 4,
        'U13' => 5,
        'U11' => 6,
        'U9' => 7,
        'U7' => 8,
        'U5' => 9,
        'Baby basket' => 10,
        'Loisir' => 11,
    ];

    public function getDescription(): string
    {
        return 'P4-36: renumérote les catégories standard (Vétéran → U5, puis Baby basket et Loisir)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ORDER as $name => $sortOrder) {
            $this->addSql('UPDATE sport_category SET sort_order = :sortOrder WHERE is_custom = false AND name = :name', [
                'sortOrder' => $sortOrder,
                'name' => $name,
            ]);
        }
    }

    /**
     * Retour à l'ordre d'origine (du plus jeune au plus vieux) : la renumérotation est une
     * donnée d'affichage, elle se défait sans perte.
     */
    public function down(Schema $schema): void
    {
        $previous = ['Baby basket' => 0, 'U5' => 1, 'U7' => 2, 'U9' => 3, 'U11' => 4, 'U13' => 5, 'U15' => 6, 'U18' => 7, 'U21' => 8, 'Senior' => 9, 'Vétéran' => 10, 'Loisir' => 11];
        foreach ($previous as $name => $sortOrder) {
            $this->addSql('UPDATE sport_category SET sort_order = :sortOrder WHERE is_custom = false AND name = :name', [
                'sortOrder' => $sortOrder,
                'name' => $name,
            ]);
        }
    }
}
