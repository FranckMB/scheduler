<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ImplicitConstraint;

final class ImplicitConstraintConfig
{
    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'venueAtMostOne' => [
                'type' => ImplicitConstraint::VENUE_AT_MOST_ONE->value,
                'enabled' => true,
                'description' => 'One venue hosts max one team per time slot',
            ],
            'coachNoOverlap' => [
                'type' => ImplicitConstraint::COACH_NO_OVERLAP->value,
                'enabled' => true,
                'description' => 'One coach coaches max one team per time slot',
            ],
            'coachPlayerNoOverlap' => [
                'type' => ImplicitConstraint::COACH_PLAYER_NO_OVERLAP->value,
                'enabled' => true,
                'description' => 'A coach-player cannot be in two roles simultaneously',
            ],
            'teamNoOverlap' => [
                'type' => ImplicitConstraint::TEAM_NO_OVERLAP->value,
                'enabled' => true,
                'description' => 'A team cannot have two sessions at the same time',
            ],
            'minSessions' => [
                'type' => ImplicitConstraint::MIN_SESSIONS->value,
                'enabled' => true,
                'description' => 'Each team gets at least its effective minimum sessions',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getConstraintsArray(): array
    {
        return array_values($this->getConfig());
    }
}
