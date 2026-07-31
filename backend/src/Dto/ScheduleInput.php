<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ScheduleInput
{
    /**
     * ADR-0002 inv. 12 : le nom vit sur le PLAN, pas sur la version. ABSENT au POST = le
     * serveur nomme la version d'après son plan (source unique) ; absent au PUT = inchangé.
     * `allowNull: true` et non `NotBlank` sec : une CHAÎNE VIDE reste refusée (400) — c'est
     * « efface le nom », qui n'a pas de sens sur une colonne NOT NULL — mais l'omission
     * devient légitime, ce qui laisse le serveur être le seul à nommer.
     */
    #[Assert\NotBlank(allowNull: true)]
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
    #[Assert\NotBlank(allowNull: true)]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $schedulePlanId = null;
}
