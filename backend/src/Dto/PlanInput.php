<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class PlanInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $name = '';

    #[Groups(['write'])]
    public int $maxTeams = 0;

    #[Groups(['write'])]
    public int $maxVenues = 0;

    #[Groups(['write'])]
    public int $maxGenerations = 0;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $monthlyPrice = '';

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $annualPrice = '';

    #[Groups(['write'])]
    public array $features = [];

}
