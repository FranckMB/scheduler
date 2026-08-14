<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\ImplicitRuleIntensity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps d'un upsert de réglage de règle implicite (PUT). La règle visée vient de l'URI
 * (`ruleKey`), jamais du corps. Les bornes de seuil et le couple règle↔seuil sont vérifiés
 * dans le processor (message 422 français), pas ici : ils dépendent de la règle de l'URI.
 */
class ImplicitRuleSettingInput
{
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [ImplicitRuleIntensity::class, 'values'])]
    #[Groups(['write'])]
    public ?string $intensity = null;

    /** Seuil de repos coach (règle coachRestDay uniquement) — borné 1-4 côté processor. */
    #[Groups(['write'])]
    public ?int $minRestDays = null;

    /** Plafond de créneaux consécutifs (règle maxConsecutiveSessions uniquement) — borné 2-6. */
    #[Groups(['write'])]
    public ?int $maxConsecutive = null;
}
