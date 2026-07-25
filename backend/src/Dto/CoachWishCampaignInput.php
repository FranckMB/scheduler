<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Écriture d'une campagne de collecte (feature #10, lot C2). Au PUT, calendarEntryId
 * identifie la campagne et n'est pas remappé (cf. le processor) ; seuls deadline, weeks et
 * teamIds bougent.
 */
class CoachWishCampaignInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $calendarEntryId = null;

    /** Date limite, format Y-m-d. */
    #[Assert\NotBlank]
    #[Assert\Date]
    #[Groups(['write'])]
    public ?string $deadline = null;

    /**
     * Semaines retenues (lundis Y-m-d ; validés lundi + fenêtre côté processor).
     *
     * @var list<string>
     */
    #[Assert\Count(min: 1, minMessage: 'Choisissez au moins une semaine.')]
    #[Assert\All([new Assert\Date])]
    #[Groups(['write'])]
    public array $weeks = [];

    /**
     * Équipes retenues.
     *
     * @var list<string>
     */
    #[Assert\Count(min: 1, minMessage: 'Choisissez au moins une équipe.')]
    #[Assert\All([new Assert\Uuid])]
    #[Assert\Unique]
    #[Groups(['write'])]
    public array $teamIds = [];
}
