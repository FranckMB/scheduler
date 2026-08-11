<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'origine d'un verrou de créneau : pourquoi ce créneau est bloqué.
 *
 * `schedule_slot_template.lock_origin` (RESERVATION | MANUAL | UNKNOWN, nullable — NULL =
 * pas de verrou) accompagne `lock_level`. Un verrou HARD né d'une réservation de gymnase
 * (on n'y touche pas) était jusqu'ici indistinguable d'un épinglage manuel (on peut le
 * retirer) : le gestionnaire n'osait rien.
 *
 * BACKFILL par jointure `Reservation`, plan par plan (une réservation de BASE, plan NULL,
 * n'alimente QUE le socle SEASON ; une réservation d'overlay alimente SON plan) :
 *   - créneau verrouillé apparié à une réservation qui l'aurait produit → RESERVATION ;
 *   - créneau verrouillé sans appariement → UNKNOWN (indécidable, jamais une devinette) ;
 *   - créneau non verrouillé → NULL (défaut de la colonne).
 * Les valeurs MANUAL ne sont écrites qu'À LA SOURCE (work-loop) sur les verrous futurs :
 * un ancien épinglage manuel devient UNKNOWN, car indistinguable des autres inconnus.
 *
 * RLS : cette table porte `FORCE ROW LEVEL SECURITY` (policy `tenant_isolation FOR ALL TO
 * app_user`). Les migrations tournent sur la connexion `admin` (owner/superuser, qui
 * BYPASSE les policies — cf. docs/security/rls.md), donc l'UPDATE de backfill voit toutes
 * les lignes de tous les clubs sans poser de GUC. Ne jamais rejouer ce backfill sur la
 * connexion runtime `app_user` : il ne verrait rien (fail-closed, 0 ligne, en silence).
 */
final class Version20260812120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'schedule_slot_template.lock_origin (RESERVATION|MANUAL|UNKNOWN, nullable) + backfill by Reservation join.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule_slot_template ADD lock_origin VARCHAR(20) DEFAULT NULL');
        $this->addSql(
            "ALTER TABLE schedule_slot_template ADD CONSTRAINT chk_schedule_slot_template_lock_origin "
            . "CHECK (lock_origin IS NULL OR lock_origin IN ('RESERVATION', 'MANUAL', 'UNKNOWN'))",
        );

        // 1. RESERVATION — un créneau verrouillé apparié à une réservation qui l'aurait
        //    produit, en respectant quel plan chaque réservation alimente réellement.
        $this->addSql(<<<'SQL'
            UPDATE schedule_slot_template t
            SET lock_origin = 'RESERVATION'
            FROM schedule s
            JOIN schedule_plan p ON p.id = s.schedule_plan_id
            WHERE t.schedule_id = s.id
              AND t.lock_level <> 'NONE'
              AND EXISTS (
                  SELECT 1 FROM reservation r
                  WHERE r.club_id = t.club_id
                    AND r.season_id = t.season_id
                    AND r.team_id = t.team_id
                    AND r.venue_id = t.venue_id
                    AND r.day_of_week = t.day_of_week
                    AND r.start_time = t.start_time
                    AND (
                        (p.type = 'SEASON' AND r.schedule_plan_id IS NULL)
                        OR (p.type <> 'SEASON' AND r.schedule_plan_id = s.schedule_plan_id)
                    )
              )
            SQL);

        // 2. UNKNOWN — verrouillé mais sans réservation à l'origine : indécidable. Jamais
        //    deviné MANUAL (un ancien épinglage manuel est indistinguable ici).
        $this->addSql(<<<'SQL'
            UPDATE schedule_slot_template t
            SET lock_origin = 'UNKNOWN'
            WHERE t.lock_level <> 'NONE' AND t.lock_origin IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule_slot_template DROP CONSTRAINT chk_schedule_slot_template_lock_origin');
        $this->addSql('ALTER TABLE schedule_slot_template DROP COLUMN lock_origin');
    }
}
