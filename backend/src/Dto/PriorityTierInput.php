<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class PriorityTierInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $label = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $color = null;

    #[Groups(['write'])]
    public ?int $orToolsWeight = null;

    #[Groups(['write'])]
    public ?int $defaultMinSessions = null;
}
