<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Read-only guard for archived seasons (spec transition-de-saison §3): once a
 * season rolls into the past (N-1 and older), it is frozen — no write may
 * target it. The listener has already resolved and validated the selected
 * season and stamped `_season_readonly` on the request; this guard turns that
 * flag into a 409 at the write choke points (the generic AbstractStateProcessor
 * for every API Platform mutation on a season-scoped entity, plus the custom
 * write controllers).
 *
 * 409 (not 423) mirrors the VALIDATED-lock idiom (ManualEditController), which
 * the frontend toast pipeline already surfaces.
 */
final class SeasonAccessGuard
{
    public function assertWritable(?Request $request): void
    {
        if (true === $request?->attributes->get('_season_readonly')) {
            throw new ConflictHttpException('This season is archived (read-only).');
        }
    }
}
