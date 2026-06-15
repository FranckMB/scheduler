<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class VenueConstraintInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $venueId = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['gender_restriction', 'level_preference'])]
    #[Groups(['write'])]
    public ?string $constraintType = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $constraintValue = null;
}
