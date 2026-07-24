<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * ADR-0002 (amendement 2026-07-24) — LE PLAN NAÎT DU GESTE D'ADAPTER.
 *
 * POST /api/schedule_plans : le geste explicite « Adapter » d'une période
 * closure/holiday. C'est la SEULE création de plan côté API — matérialiser une
 * période n'en crée plus (l'entrée mère est un ancrage). Idempotent : la période
 * a déjà son plan → il est rendu tel quel.
 */
class CreatePeriodPlanInput
{
    #[Assert\NotBlank(message: 'calendarEntryId est requis.')]
    #[Assert\Uuid(message: 'calendarEntryId doit être un UUID.')]
    #[Groups(['write'])]
    public string $calendarEntryId = '';
}
