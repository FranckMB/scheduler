<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ClubUserInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $userId = '';

    #[Assert\Choice(choices: ["owner", "admin", "editor", "viewer"])]
    #[Groups(['write'])]
    public string $role = '';

    #[Groups(['write'])]
    public bool $isActive = false;

}
