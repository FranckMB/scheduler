<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class VenueUnavailabilityInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $venueId = null;

    /** Y-m-d, inclusive. */
    #[Assert\NotBlank]
    #[Assert\Date]
    #[Groups(['write'])]
    public ?string $startDate = null;

    /** Y-m-d, inclusive. */
    #[Assert\NotBlank]
    #[Assert\Date]
    #[Groups(['write'])]
    public ?string $endDate = null;

    #[Assert\Length(max: 180)]
    #[Groups(['write'])]
    public ?string $label = null;
}
