<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-0002 Lot B1 — the plan pointer becomes the single truth. NON-DESTRUCTIVE.
 *
 * 1. POINTER REPAIR — applies to EVERY plan (SEASON and CLOSURE/HOLIDAY), on
 *    purpose: lot A seeded both kinds from a pointer that is AUTO-assigned, never a
 *    manager validation.
 *      - SEASON plans were seeded from `season.baseline_schedule_id`, which
 *        GenerateScheduleHandler sets on the first COMPLETED solve ("first
 *        successful season plan wins the baseline").
 *      - CLOSURE/HOLIDAY plans were seeded from `calendar_entry.overlay_schedule_id`,
 *        which ScheduleStateProcessor sets at overlay CREATION whenever the period
 *        has no usable active version.
 *    The pointer means "the manager CHOSE this version" (inv. 1), so it is recomputed
 *    from the only real evidence of a choice: a VALIDATED version. A plan that was
 *    never validated goes back to NULL = espace de travail (inv. 2) — including a
 *    period whose overlay is merely COMPLETED, which is exactly right: nobody chose it.
 *    Nothing is lost: the legacy pointers themselves are untouched and still decide.
 *
 * 2. `schedule_plan.last_version_number`: a MONOTONIC counter, seeded from the
 *    MAX(version_number) of the versions that STILL EXIST. Nothing records the
 *    numbers of versions deleted BEFORE this migration, so a club that had V1..V3
 *    and deleted V3 is seeded at 2 and will hand out "3" once more — the high-water
 *    mark is simply not recoverable from the data. From here on the counter only
 *    ever grows, so no number is reused again.
 *
 * NO retroactive deletion (founder decision, revised 2026-07-16): invariant 1
 * ("choosing a version deletes the others") applies to FUTURE validations — the base
 * aligns itself at the next validation. An earlier draft deleted the non-chosen
 * versions of pointed plans; review showed it destroyed live data irreversibly
 * (a plan promoted via set-baseline but never validated would lose the very version
 * the club uses), for no gain — so nothing is deleted here.
 *
 * Legacy is NOT dropped either (lot D): baseline_schedule_id, socle_validated_at and
 * the VALIDATED/ARCHIVED statuses keep being WRITTEN as a mirror — simply never read
 * to decide anymore.
 */
final class Version20260716130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-0002 lot B1: repair schedule_plan.chosen_schedule_id (a real validation, not the auto baseline) + monotonic last_version_number.';
    }

    public function up(Schema $schema): void
    {
        // 1. Pointer repair — see the class docblock. A plan with no VALIDATED
        //    version becomes an "espace de travail" (NULL pointer).
        $this->addSql(
            'UPDATE schedule_plan p SET chosen_schedule_id = ('
            . 'SELECT s.id FROM schedule s '
            . 'WHERE s.schedule_plan_id = p.id AND s.status = \'VALIDATED\' '
            . 'ORDER BY s.updated_at DESC, s.id LIMIT 1)',
        );

        // 2. Monotonic counter, seeded from the highest number handed out so far.
        $this->addSql('ALTER TABLE schedule_plan ADD last_version_number INT DEFAULT 0 NOT NULL');
        $this->addSql(
            'UPDATE schedule_plan p SET last_version_number = COALESCE( '
            . '(SELECT MAX(s.version_number) FROM schedule s WHERE s.schedule_plan_id = p.id), 0)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule_plan DROP last_version_number');
    }
}
