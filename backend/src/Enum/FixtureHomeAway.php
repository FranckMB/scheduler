<?php

declare(strict_types=1);

namespace App\Enum;

/** Whether the club receives the fixture (HOME — placeable) or plays away (AWAY — counted, time estimated). */
enum FixtureHomeAway: string
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
    case HOME = 'HOME';
    case AWAY = 'AWAY';
}
