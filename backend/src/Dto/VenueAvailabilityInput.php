<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class VenueAvailabilityInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $venueId = '';

    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['write'])]
    public int $dayOfWeek = 0;

    #[Groups(['write'])]
    public \DateTimeImmutable $startTime;

    #[Groups(['write'])]
    public \DateTimeImmutable $endTime;

}
