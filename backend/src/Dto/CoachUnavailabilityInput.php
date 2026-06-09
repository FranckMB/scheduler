<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class CoachUnavailabilityInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $coachId = null;

    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['write'])]
    public ?int $dayOfWeek = null;

    #[Groups(['write'])]
    public ?\DateTimeImmutable $startTime = null;

    #[Groups(['write'])]
    public ?\DateTimeImmutable $endTime = null;
}
