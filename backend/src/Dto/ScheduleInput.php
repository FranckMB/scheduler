<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ScheduleInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['DRAFT', 'PENDING', 'GENERATING', 'COMPLETED', 'FAILED'])]
    #[Groups(['write'])]
    public ?string $status = null;

    #[Groups(['write'])]
    public ?int $solverSeed = null;

    /**
     * ADR-0002 C4 : POST crée une version SOUS un plan nommé. Fourni → ce plan (overlay de
     * période) ; omis → le plan SEASON de la saison (le socle). Ignoré sur PUT. Le back valide
     * que le plan appartient au club.
     */
    #[Groups(['write'])]
    public ?string $schedulePlanId = null;
}
