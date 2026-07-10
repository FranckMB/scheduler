<?php

declare(strict_types=1);

namespace App\Dto;

use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class SeasonInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $name = null;

    #[Groups(['write'])]
    public DateTimeImmutable $startDate;

    #[Groups(['write'])]
    public DateTimeImmutable $endDate;

    #[Assert\Choice(choices: ['draft', 'active', 'archived', 'closed'])]
    #[Groups(['write'])]
    public ?string $status = null;

    /** Name of THE season plan (planning-versions) — null keeps the current value. */
    #[Assert\Length(max: 120)]
    #[Groups(['write'])]
    public ?string $planningName = null;
}
