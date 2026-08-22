<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\TeamLinkIntensity;
use App\Enum\TeamLinkType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class TeamLinkInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $teamAId = null;

    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $teamBId = null;

    #[Assert\NotBlank]
    #[Assert\Choice(callback: [TeamLinkType::class, 'values'])]
    #[Groups(['write'])]
    public ?string $linkType = null;

    /**
     * Intensité côté entraînement. Optionnel : absent ⇒ PREFERRED (rétro-compatible).
     * Pas de NotBlank — seule l'appartenance à l'enum est contrainte.
     */
    #[Assert\Choice(callback: [TeamLinkIntensity::class, 'values'])]
    #[Groups(['write'])]
    public ?string $trainingIntensity = null;
}
