<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class VenueTrainingSlotInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $venueId = null;

    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['write'])]
    public ?int $dayOfWeek = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{2}:\d{2}$/')]
    #[Groups(['write'])]
    public ?string $startTime = null;

    #[Assert\NotBlank]
    #[Assert\Range(min: 15)]
    #[Groups(['write'])]
    public ?int $durationMinutes = null;

    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 2)]
    #[Groups(['write'])]
    public ?int $capacity = 1;
}
