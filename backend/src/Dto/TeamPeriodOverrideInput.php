<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class TeamPeriodOverrideInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $calendarEntryId = null;

    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $teamId = null;

    #[Groups(['write'])]
    public bool $isActive = true;

    /** null = keep the team's seasonal sessionsPerWeek. */
    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['write'])]
    public ?int $sessionsPerWeek = null;
}
