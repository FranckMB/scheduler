<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\LockLevel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #8 (décision fondateur 2026-07-24) — UN ÉPINGLAGE ORPHELIN BLOQUE LA GÉNÉRATION.
 *
 * Une période possède sa grille : refaire cette grille (repartir d'une page blanche,
 * recopier le modèle de saison, désactiver un gymnase) peut laisser un verrou de
 * work-loop ou une réservation qui ne correspond plus à aucun créneau. Le filtrer en
 * silence replacerait la séance ailleurs sans rien dire ; le laisser passer épinglerait
 * une séance sur un créneau inexistant.
 *
 * « On ne fait pas de chose magique, on voit et on informe le gestionnaire. C'est lui qui
 * prend la décision » : on refuse la génération avec un message qui nomme le gymnase et
 * le jour, à charge pour lui de redéfinir ses créneaux ou de retirer l'épinglage.
 *
 * Ne concerne QUE les plannings de période : le socle définit lui-même sa grille.
 */
final class OrphanPinGuard
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /**
     * Le message à afficher, ou null si tout épinglage retombe sur un créneau existant.
     */
    public function firstOrphanMessage(Schedule $schedule): ?string
    {
        if ($this->schedulePlanProvisioner->isSeasonSchedule($schedule)) {
            return null;
        }
        $schedulePlanId = $schedule->getSchedulePlanId();

        // Les créneaux RÉELS de la période, par (gymnase, jour, heure) — c'est ce qu'un
        // épinglage désigne : ni un verrou ni une réservation ne cite l'id du créneau.
        $available = [];
        foreach ($this->entityManager->getRepository(VenueTrainingSlot::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $slot) {
            $available[$this->key($slot->getVenueId(), $slot->getDayOfWeek(), $slot->getStartTime()->format('H:i'))] = true;
        }

        // Verrous HARD du work-loop de CETTE version. Les placements SOFT/NONE sont des
        // RÉSULTATS de solve, pas des épinglages : les refuser bloquerait toute
        // régénération après un simple changement de grille.
        foreach ($this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $schedule->getId()]) as $lock) {
            if (LockLevel::HARD !== $lock->getLockLevel()) {
                continue;
            }
            if (!isset($available[$this->key($lock->getVenueId(), $lock->getDayOfWeek(), $lock->getStartTime()->format('H:i'))])) {
                return $this->message($lock->getVenueId(), $lock->getDayOfWeek());
            }
        }

        foreach ($this->entityManager->getRepository(Reservation::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $reservation) {
            if (!isset($available[$this->key($reservation->getVenueId(), $reservation->getDayOfWeek(), $reservation->getStartTime()->format('H:i'))])) {
                return $this->message($reservation->getVenueId(), $reservation->getDayOfWeek());
            }
        }

        return null;
    }

    private function key(string $venueId, int $dayOfWeek, string $startTime): string
    {
        return $venueId . '|' . $dayOfWeek . '|' . $startTime;
    }

    private function message(string $venueId, int $dayOfWeek): string
    {
        $venue = $this->entityManager->getRepository(Venue::class)->find($venueId);
        $days = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

        return \sprintf(
            'Les créneaux du %s à %s ne sont plus disponibles en l’état : une séance y est épinglée sans créneau correspondant. Redéfinissez les créneaux de ce gymnase pour la période, ou retirez l’épinglage.',
            $days[$dayOfWeek] ?? 'jour ' . $dayOfWeek,
            $venue?->getName() ?? 'ce gymnase',
        );
    }
}
