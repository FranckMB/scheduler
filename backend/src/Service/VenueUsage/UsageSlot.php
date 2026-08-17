<?php

declare(strict_types=1);

namespace App\Service\VenueUsage;

/**
 * Une séance placée, réduite à ce dont le calcul d'utilisation a besoin (P3-22) :
 * son gymnase, son jour ISO (1=lundi … 7=dimanche), sa durée et l'équipe qui
 * l'occupe (pour la ventilation par niveau). Volontairement détaché de
 * ScheduleSlotTemplate pour que VenueUsageCalculator reste pur (testable sans DB).
 */
final readonly class UsageSlot
{
    public function __construct(
        public string $venueId,
        public int $dayOfWeek,
        public int $durationMinutes,
        public string $teamId,
    ) {}
}
