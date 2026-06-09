<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class SportInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $slug = null;

    #[Groups(['write'])]
    public ?string $icon = null;

    #[Groups(['write'])]
    public ?bool $isActive = null;
}
