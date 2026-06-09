<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class PlanInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $name = null;

    #[Groups(['write'])]
    public ?int $maxTeams = null;

    #[Groups(['write'])]
    public ?int $maxVenues = null;

    #[Groups(['write'])]
    public ?int $maxGenerations = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $monthlyPrice = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $annualPrice = null;

    /** @var array<string, mixed>|null */
    #[Groups(['write'])]
    public ?array $features = null;
}
