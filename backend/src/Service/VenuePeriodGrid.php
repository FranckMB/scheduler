<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\VenueTrainingSlot;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La grille d'UN gymnase POUR UNE période — le seul endroit qui la vide ou la reprend.
 *
 * Une période possède sa grille (#8) : ses créneaux sont une copie du modèle de saison
 * faite à la naissance du plan. Deux gestes la refont, et tous deux DÉTRUISENT (les
 * réservations et les verrous du gymnase partent avec ses créneaux) : « vider » et
 * « reprendre la grille du planning principal ».
 *
 * Foyer unique et volontaire : ces gestes sont appelés depuis le processeur de mode ET
 * depuis l'action de reprise. Deux des défauts du round 4 de revue de #8 venaient
 * exactement de logiques destructrices écrites à deux endroits — l'une avait la borne
 * de couche, l'autre pas.
 *
 * ⚠️ Ce service ne touche JAMAIS aux créneaux de saison : il ne travaille que sur des
 * lignes portant `schedulePlanId`, jamais sur celles où il est nul (invariant fondateur
 * n°1 — le planning principal n'est jamais modifié par une période).
 */
final class VenuePeriodGrid
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EntityCascadeDeleter $cascadeDeleter,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /**
     * Vide la grille d'un gymnase pour cette période. Les réservations du créneau et les
     * verrous HARD qu'elles ont matérialisés partent avec lui : un `remove()` nu les
     * laisserait orphelins, et OrphanPinGuard refuserait ensuite toute génération.
     */
    public function clear(string $schedulePlanId, string $venueId): void
    {
        $slots = $this->entityManager->getRepository(VenueTrainingSlot::class)
            ->findBy(['schedulePlanId' => $schedulePlanId, 'venueId' => $venueId]);
        foreach ($slots as $slot) {
            $this->cascadeDeleter->purgeChildrenOfSlot($slot);
            $this->entityManager->remove($slot);
        }
        $this->entityManager->flush();
    }

    /**
     * Reprend la grille du planning principal pour ce gymnase : on vide, puis on RECOPIE.
     * L'ordre importe — recopier avant de vider supprimerait la copie fraîche.
     *
     * Idempotent : rejouer la reprise redonne la même grille, jamais des doublons.
     */
    public function resetFromSeason(string $schedulePlanId, string $venueId): void
    {
        $this->entityManager->wrapInTransaction(function () use ($schedulePlanId, $venueId): void {
            $this->clear($schedulePlanId, $venueId);
            $this->schedulePlanProvisioner->copySeasonalSlotsForVenue($schedulePlanId, $venueId);
        });
    }
}
