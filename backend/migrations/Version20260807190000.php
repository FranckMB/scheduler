<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SEC-13 PR B — `config.coachId` disparaît : c'était un doublon exact du scope.
 *
 * Sur les contraintes `COACH_AVAILABILITY`, `config.coachId` valait EXACTEMENT
 * `scope_target_id` (6 lignes sur 6, mesuré). Le solveur, lui, lit le scope
 * (`constraints.py` : `scope_target_id`) et ignore la clé du config. C'était donc
 * une seconde vérité qui dormait — le genre de doublon qui finit par diverger, et
 * le jour où il diverge personne ne sait laquelle des deux fait foi.
 *
 * Deux endroits à nettoyer, et le second est celui qu'on oublie :
 * 1. les contraintes VIVES (`constraint.config`) ;
 * 2. les **photos de structure** (`schedule_structure_snapshot.data`), qui
 *    contiennent les configs telles qu'elles étaient au moment du cliché — 36
 *    entrées ici. Sans ce second nettoyage, « Charger cette version » réinjecte
 *    la clé en base, et la validation stricte de la PR A la refuse ensuite : le
 *    récap bloquerait la génération sur une donnée que le gestionnaire n'a pas
 *    saisie.
 *
 * Aucune perte : la valeur reste dans `scope_target_id`, qui est la source que
 * tout le monde lit déjà.
 *
 * ⚠ Piège SQL rencontré ici : l'opérateur JSONB `?` (« la clé existe ») est lu
 * comme un PARAMÈTRE POSITIONNEL par PDO — « config::jsonb $1 'coachId' » et la
 * migration meurt. On écrit donc `jsonb_exists(x, 'clé')`, strictement équivalent
 * et sans point d'interrogation.
 */
final class Version20260807190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SEC-13 B: drop the redundant config.coachId from constraints AND from structure snapshots.';
    }

    public function up(Schema $schema): void
    {
        // 1. Les contraintes vives. Borné à COACH_AVAILABILITY : c'est la seule
        //    famille où la clé existe, et le `type` legacy TEAM_COACH porte un
        //    `metadata.coachId` que le moteur LIT — celui-là ne bouge pas.
        $this->addSql(<<<'SQL'
            UPDATE "constraint"
            SET config = (config::jsonb - 'coachId')::json, updated_at = NOW()
            WHERE family = 'COACH_AVAILABILITY' AND jsonb_exists(config::jsonb, 'coachId')
            SQL);

        // 2. Les photos de structure. On reconstruit le tableau `Constraint` en
        //    retirant la clé de chaque `config`, ORDINALITY à l'appui pour que
        //    l'ordre du cliché soit préservé — un restore doit rendre la même
        //    structure, pas la même structure mélangée.
        $this->addSql(<<<'SQL'
            UPDATE schedule_structure_snapshot s
            SET data = jsonb_set(
                    s.data::jsonb,
                    '{Constraint}',
                    COALESCE((
                        SELECT jsonb_agg(
                            CASE WHEN jsonb_exists(e.item->'config', 'coachId')
                                 THEN jsonb_set(e.item, '{config}', (e.item->'config') - 'coachId')
                                 ELSE e.item
                            END ORDER BY e.ord
                        )
                        FROM jsonb_array_elements(s.data::jsonb->'Constraint') WITH ORDINALITY AS e(item, ord)
                    ), '[]'::jsonb)
                )::json,
                updated_at = NOW()
            WHERE jsonb_exists(s.data::jsonb, 'Constraint')
              AND EXISTS (
                  SELECT 1 FROM jsonb_array_elements(s.data::jsonb->'Constraint') c
                  WHERE jsonb_exists(c->'config', 'coachId')
              )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Réversible sans perte : la valeur vit dans scope_target_id. On ne
        // restaure QUE les contraintes vives — remettre la clé dans les photos
        // demanderait de retrouver le scope de chaque ligne du cliché, et ce
        // doublon n'a aucune raison de revivre.
        $this->addSql(<<<'SQL'
            UPDATE "constraint"
            SET config = jsonb_set(config::jsonb, '{coachId}', to_jsonb(scope_target_id::text))::json
            WHERE family = 'COACH_AVAILABILITY' AND scope_target_id IS NOT NULL
            SQL);
    }
}
