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
    #[Groups(['write'])]
    public ?string $teamId = null;

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

    #[Groups(['write'])]
    public ?string $venueId = null;

    /** HH:MM kickoff. */
    #[Assert\Regex(pattern: '/^\d{2}:\d{2}$/', message: 'kickoffTime must be HH:MM.')]
    #[Groups(['write'])]
    public ?string $kickoffTime = null;
}
