<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Déclaration de mutualisation : N équipes ensemble, EXACTEMENT K séances communes.
 * Les bornes fines (équipe déjà dans un autre groupe du même plan, K > min sessionsPerWeek)
 * vivent dans le processor (elles exigent la base) ; ici les invariants purement de forme.
 */
class SharedTrainingGroupInput
{
    /** NULL = socle saison ; un UUID = plan de période. */
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $schedulePlanId = null;

    /**
     * De 2 (minimum métier) à 10 équipes (cap technique fondateur). Chaque id UUID, aucun doublon.
     *
     * @var list<string>
     */
    #[Assert\Count(min: 2, max: 10, minMessage: 'Un groupe mutualisé compte au moins {{ limit }} équipes.', maxMessage: 'Un groupe mutualisé compte au plus {{ limit }} équipes.')]
    #[Assert\Unique(message: 'Une équipe ne peut figurer qu\'une fois dans le groupe.')]
    #[Assert\All([new Assert\NotBlank, new Assert\Uuid])]
    #[Groups(['write'])]
    public array $teamIds = [];

    /** Nombre EXACT de séances communes (≥ 1 ; ≤ min sessionsPerWeek effectif, vérifié côté processor). */
    #[Assert\NotNull]
    #[Assert\Positive]
    #[Groups(['write'])]
    public ?int $commonSessions = null;
}
