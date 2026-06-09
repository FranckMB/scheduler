<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class TeamCoachInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $teamId = '';

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $coachId = '';

    #[Assert\Choice(choices: ["head", "assistant", "trainer"])]
    #[Groups(['write'])]
    public string $role = '';

    #[Groups(['write'])]
    public bool $isRequired = false;

}
