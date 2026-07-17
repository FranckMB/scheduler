<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\CalendarEntry;
use App\Service\SchedulePlanProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;

/**
 * ADR-0002 lot C — l'ancre des réglages de période est son PLAN (inv. 5).
 *
 * En production, le plan naît DU GESTE : créer une période via `POST /api/calendar_entries`
 * crée son plan dans la même transaction (lot C1, `CalendarEntryStateProcessor`). Un test
 * qui fabrique sa `CalendarEntry` à la main court-circuite ce chemin : il doit rejouer le
 * geste, sinon la période n'a pas de plan et rien ne peut s'y accrocher.
 *
 * À n'utiliser QUE pour une entrée construite à la main. Un test qui passe par l'API a déjà
 * son plan — il le résout avec `SchedulePlanProvisioner::periodPlanId()`.
 */
trait ProvisionsPeriodPlanTrait
{
    /**
     * Rejoue le geste sur une entrée fabriquée à la main, et rend l'ancre de ses réglages.
     * Flush d'abord : le provisioner relit la ligne en SQL brut (il contourne le
     * season_filter, cf. son docblock), donc elle doit être en base.
     */
    private function planIdOf(CalendarEntry $entry): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();

        $planId = static::getContainer()->get(SchedulePlanProvisioner::class)->provisionPeriodPlan($entry->getId());
        Assert::assertIsString($planId, 'la période doit porter un plan (type closure/holiday attendu — inv. 9)');

        return $planId;
    }
}
