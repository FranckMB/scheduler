<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\TeamLevel;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class TeamInput
{
    #[Groups(['write'])]
    public ?string $sportCategoryId = null;

    #[Groups(['write'])]
    public ?int $priorityTierId = null;

    #[Groups(['write'])]
    public ?int $tierOrder = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $name = null;

    #[Assert\Choice(choices: ['M', 'F', 'MIXTE'])]
    #[Groups(['write'])]
    public ?string $gender = null;

    #[Groups(['write'])]
    public ?int $sessionsPerWeek = null;

    #[Groups(['write'])]
    public ?int $minSessionsOverride = null;

    #[Groups(['write'])]
    public ?int $matchDay = null;

    #[Groups(['write'])]
    public ?string $forcedVenueId = null;

    #[Groups(['write'])]
    public ?bool $isActive = null;

    #[Groups(['write'])]
    public ?string $parentTeamId = null;

    #[Groups(['write'])]
    public ?string $ffbbTeamId = null;

    #[Assert\Choice(callback: [TeamLevel::class, 'values'])]
    #[Groups(['write'])]
    public ?string $level = null;
}
