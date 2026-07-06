<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class FixtureInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $teamId = null;

    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $competitionId = null;

    #[Assert\NotBlank]
    #[Assert\Date]
    #[Groups(['write'])]
    public ?string $matchDate = null;

    #[Assert\NotBlank]
    #[Assert\Choice(callback: [FixtureHomeAway::class, 'values'])]
    #[Groups(['write'])]
    public ?string $homeAway = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $opponentLabel = null;

    #[Assert\Choice(callback: [FixtureStatus::class, 'values'])]
    #[Groups(['write'])]
    public ?string $status = null;

    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $venueId = null;

    /** HH:MM kickoff (24h, strict range). */
    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/', message: 'kickoffTime must be a valid HH:MM (00:00–23:59).')]
    #[Groups(['write'])]
    public ?string $kickoffTime = null;
}
