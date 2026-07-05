<?php

declare(strict_types=1);

namespace App\Dto;

use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ClubInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $slug = null;

    #[Groups(['write'])]
    public ?int $planId = null;

    #[Assert\Choice(choices: ['monthly', 'annual', 'quarterly'])]
    #[Groups(['write'])]
    public ?string $billingCycle = null;

    #[Groups(['write'])]
    public ?DateTimeImmutable $planExpiresAt = null;

    #[Groups(['write'])]
    public ?int $generationCountSeason = null;

    #[Assert\Choice(choices: ['A', 'B', 'C'], message: 'School zone must be A, B or C.')]
    #[Groups(['write'])]
    public ?string $schoolZone = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $timezone = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $locale = null;

    #[Groups(['write'])]
    public ?bool $onboardingCompleted = null;

    #[Groups(['write'])]
    public ?string $ffbbClubCode = null;

    #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'accentColor must be a #RRGGBB hex colour')]
    #[Groups(['write'])]
    public ?string $accentColor = null;

    /**
     * @var list<string>|null
     */
    #[Assert\All([new Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/')])]
    #[Groups(['write'])]
    public ?array $accentPalette = null;
}
