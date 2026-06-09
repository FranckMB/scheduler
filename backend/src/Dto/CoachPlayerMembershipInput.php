<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class CoachPlayerMembershipInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $coachId = '';

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $teamId = '';

    #[Groups(['write'])]
    public ?string $position = null;

    #[Groups(['write'])]
    public bool $isActive = false;

}
