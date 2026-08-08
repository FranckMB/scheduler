<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\TeamCoachRole;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class TeamCoachInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $teamId = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $coachId = null;

    #[Assert\Choice(callback: [TeamCoachRole::class, 'values'])]
    #[Groups(['write'])]
    public ?string $role = null;

    #[Groups(['write'])]
    public ?bool $isRequired = null;
}
