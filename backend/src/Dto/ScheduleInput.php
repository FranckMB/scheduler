<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\ScheduleStatus;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ScheduleInput
{
    /**
     * ADR-0002 inv. 12 : le nom vit sur le PLAN, pas sur la version. ABSENT au POST = le
     * serveur nomme la version d'après son plan (source unique) ; absent au PUT = inchangé.
     * `allowNull: true` et non `NotBlank` sec : une chaîne VIDE — ou blanche, d'où le
     * `normalizer: 'trim'` que porte déjà le `SchedulePlanInput` voisin — reste refusée
     * (**422**, la réponse de validation d'API Platform) : c'est « efface le nom », qui n'a
     * pas de sens sur une colonne NOT NULL. Mais l'omission devient légitime, ce qui laisse
     * le serveur être le seul à nommer.
     */
    #[Assert\NotBlank(allowNull: true, normalizer: 'trim')]
    #[Assert\Length(max: 180)]
    #[Groups(['write'])]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Assert\Choice(callback: [ScheduleStatus::class, 'values'])]
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
