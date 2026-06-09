<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class TeamInput
{
    #[Groups(['write'])]
    public ?string $sportCategoryId = null;

    #[Groups(['write'])]
    public ?int $priorityTierId = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $name = null;

    #[Assert\Choice(choices: ['M', 'F', 'mixed'])]
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
}
