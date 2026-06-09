<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class SportCategoryInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $sportId = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $name = null;

    #[Groups(['write'])]
    public ?bool $isCustom = null;

    #[Groups(['write'])]
    public ?int $ageMin = null;

    #[Groups(['write'])]
    public ?int $ageMax = null;

    #[Groups(['write'])]
    public ?int $sortOrder = null;
}
