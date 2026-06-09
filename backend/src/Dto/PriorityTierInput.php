<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class PriorityTierInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $label = '';

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $name = '';

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $color = '';

    #[Groups(['write'])]
    public int $orToolsWeight = 0;

    #[Groups(['write'])]
    public int $defaultMinSessions = 0;

}
