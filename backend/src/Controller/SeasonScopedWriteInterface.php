<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Marker for custom controllers whose action WRITES season-scoped data
 * (schedules, teams, constraints…). SeasonReadonlyGuardListener refuses these
 * with a 409 when the selected season is archived (read-only), mirroring the
 * SeasonAccessGuard applied to the generic API Platform processors.
 *
 * Do NOT implement on controllers that touch club/user-level data only
 * (appearance, profile) — those stay editable whatever season is selected.
 */
interface SeasonScopedWriteInterface {}
