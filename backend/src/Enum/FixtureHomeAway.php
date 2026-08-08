<?php

declare(strict_types=1);

namespace App\Enum;

/** Whether the club receives the fixture (HOME — placeable) or plays away (AWAY — counted, time estimated). */
enum FixtureHomeAway: string
{
    use HasValues;

    case HOME = 'HOME';
    case AWAY = 'AWAY';
}
