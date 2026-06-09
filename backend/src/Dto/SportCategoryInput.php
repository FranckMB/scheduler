<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class SportCategoryInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $sportId = '';

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $name = '';

    #[Groups(['write'])]
    public bool $isCustom = false;

    #[Groups(['write'])]
    public ?int $ageMin = null;

    #[Groups(['write'])]
    public ?int $ageMax = null;

    #[Groups(['write'])]
    public int $sortOrder = 0;

}
