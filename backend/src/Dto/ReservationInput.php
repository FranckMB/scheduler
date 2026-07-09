<?php

declare(strict_types=1);

namespace App\Dto;

use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ReservationInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $teamId = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $venueId = null;

    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['write'])]
    public ?int $dayOfWeek = null;

    #[Groups(['write'])]
    public DateTimeImmutable $startTime;

    #[Assert\Range(min: 15, max: 300)]
    #[Groups(['write'])]
    public ?int $durationMinutes = 90;

    /** NULL = base plan; set = a period overlay. */
    #[Groups(['write'])]
    public ?string $calendarEntryId = null;
}
