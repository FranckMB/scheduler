<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class VenueTrainingSlotInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $venueId = null;

    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['write'])]
    public ?int $dayOfWeek = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{2}:\d{2}$/')]
    #[Groups(['write'])]
    public ?string $startTime = null;

    #[Assert\NotBlank]
    #[Assert\Range(min: 15)]
    #[Groups(['write'])]
    public ?int $durationMinutes = 90;

    /**
     * Nombre d'équipes simultanées sur le créneau — 3 depuis le 2026-08-05 (certains terrains
     * se divisent en trois en travers : cas ADN au BCCL).
     *
     * ⚠ Cette borne est la PORTE D'ENTRÉE, et elle est restée à 2 quand l'écran est passé à 3 :
     * `slotFields.tsx` offrait « 3 équipes » et l'API répondait 422. La chaîne AVAL était bien
     * générique (`ScheduleConstraintBuilder:610` lit `getCapacity()`, l'engine borne à `ge=1`
     * sans plafond, le picker de réservation suit `capacity`) — c'est précisément ce qui a
     * masqué le trou : tout le monde a vérifié l'aval, personne la porte.
     */
    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 3)]
    #[Groups(['write'])]
    public ?int $capacity = 1;

    /** Period-editable structure: null = seasonal slot; a period id scopes the slot to that period (additive). */
    // `NotBlank(allowNull: true)` en plus d'`Uuid` : le validateur Uuid de Symfony
    // laisse passer la chaîne VIDE, qui atteindrait la colonne `uuid` en base (22P02).
    // Même garde que `ReservationInput` — ce DTO ne l'avait jamais eu.
    #[Assert\NotBlank(allowNull: true)]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $schedulePlanId = null;
}
