<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Uid\Uuid;

/**
 * planning-versions (P0-5): the engine returns a slot id that is deterministic on
 * the PLACEMENT alone (uuid5 of team:venue:day:start — result_builder._slot_id),
 * so two schedules (versions of a season plan, or overlay versions of a period)
 * sharing a placement would get the SAME id. Since that id is the row primary
 * key, the second import used to steal the first schedule's row.
 *
 * scope() namespaces the engine id by the schedule → the id is unique per
 * (schedule, placement), yet stays DETERMINISTIC within a schedule (so re-importing
 * a regeneration still matches — and preserves — the schedule's own HARD-locked
 * rows). Pure + static so the data migration can reuse it without the container.
 */
final class SlotIdScoper
{
    /** Fixed application namespace for per-schedule slot ids (never change — it anchors existing rows). */
    private const NAMESPACE = '9e2b7c14-3a5f-4d21-8b6e-1f0a2c3d4e5f';

    public static function scope(string $scheduleId, string $engineSlotId): string
    {
        return Uuid::v5(Uuid::fromString(self::NAMESPACE), $scheduleId . ':' . $engineSlotId)->toRfc4122();
    }
}
