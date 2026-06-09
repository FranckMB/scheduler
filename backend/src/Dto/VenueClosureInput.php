<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class VenueClosureInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $venueId = '';

    #[Groups(['write'])]
    public \DateTimeImmutable $dateStart;

    #[Groups(['write'])]
    public \DateTimeImmutable $dateEnd;

    #[Groups(['write'])]
    public ?string $reason = null;

}
