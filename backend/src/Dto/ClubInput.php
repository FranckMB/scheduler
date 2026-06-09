<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ClubInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $name = '';

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $slug = '';

    #[Groups(['write'])]
    public ?int $planId = null;

    #[Assert\Choice(choices: ["monthly", "annual", "quarterly"])]
    #[Groups(['write'])]
    public ?string $billingCycle = null;

    #[Groups(['write'])]
    public ?\DateTimeImmutable $planExpiresAt = null;

    #[Groups(['write'])]
    public int $generationCountSeason = 0;

    #[Groups(['write'])]
    public ?string $schoolZone = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $timezone = '';

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $locale = '';

    #[Groups(['write'])]
    public bool $onboardingCompleted = false;

    #[Groups(['write'])]
    public ?string $ffbbClubCode = null;

}
