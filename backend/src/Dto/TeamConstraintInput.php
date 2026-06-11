<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class TeamConstraintInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $teamId = null;

    #[Assert\Choice(choices: ['preferred', 'avoid', 'forbidden', 'required'])]
    #[Groups(['write'])]
    public ?string $type = null;

    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['write'])]
    public ?int $dayOfWeek = null;

    #[Groups(['write'])]
    public ?\DateTimeImmutable $startTime = null;

    #[Groups(['write'])]
    public ?\DateTimeImmutable $endTime = null;

    #[Groups(['write'])]
    public ?string $venueId = null;

    #[Groups(['write'])]
    public ?string $reason = null;

    #[Groups(['write'])]
    public ?string $createdBy = null;

    #[Groups(['write'])]
    public ?string $sourceOccurrenceId = null;

    #[Groups(['write'])]
    public ?string $severity = null;
}
