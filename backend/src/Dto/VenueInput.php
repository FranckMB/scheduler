<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class VenueInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $name = null;

    #[Groups(['write'])]
    public ?bool $isExternal = null;

    #[Groups(['write'])]
    public ?string $color = null;

    #[Groups(['write'])]
    public ?string $latitude = null;

    #[Groups(['write'])]
    public ?string $longitude = null;

    #[Assert\Choice(choices: ['manual', 'ffbb', 'import'])]
    #[Groups(['write'])]
    public ?string $source = null;

    #[Groups(['write'])]
    public ?string $externalRef = null;

    #[Groups(['write'])]
    public ?bool $isActive = null;

    #[Groups(['write'])]
    public ?string $parentVenueId = null;
}
