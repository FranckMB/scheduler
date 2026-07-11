<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The axis a team tag belongs to, for grouping the constraint target picker
 * (GENRE / NIVEAU / ÂGE). The 21 system tags each map deterministically to one
 * axis (see TeamTagService::SYSTEM_TAG_AXES); a tag outside the three axes keeps
 * a null axis — there is no "other" bucket.
 */
enum TeamTagAxis: string
{
    case GENRE = 'GENRE';
    case NIVEAU = 'NIVEAU';
    case AGE = 'AGE';
}
