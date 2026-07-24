<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class VenuePeriodOverrideInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $schedulePlanId = null;

    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $venueId = null;

    /**
     * INHERIT n'est pas une valeur acceptée : c'est le défaut, matérialisé par l'ABSENCE
     * de ligne. Pour revenir aux créneaux de saison on supprime l'override (DELETE).
     */
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['DISABLED', 'BLANK'], message: 'Mode invalide : seuls DISABLED et BLANK se stockent. INHERIT est le défaut — supprimez l\'override pour y revenir.')]
    #[Groups(['write'])]
    public ?string $mode = null;
}
