<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\LockLevel;
use App\Enum\VenuePeriodMode;
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
     * LE prédicat ÉTROIT « réservation dont le triplet (gymnase, jour, heure) ne retombe
     * sur AUCUN créneau de la grille » — extrait pour la parité MÉCANIQUE avec le front
     * (`wizard/lib/orphanReservations.ts::orphanReservationIds`, cas partagés
     * `orphanReservations.parity.json`, gardé par `OrphanReservationsMirrorParityTest`).
     *
     * ⚠ SOUS-ENSEMBLE ASSUMÉ de `firstOrphanMessage` : le bloqueur complet retire EN PLUS
     * les gymnases désactivés et les jours de fermeture, et couvre les verrous HARD. Le
     * front LISTE (pour supprimer au récap), le backend REFUSE la génération. La parité ne
     * porte QUE sur ce prédicat étroit — les cas « le bloqueur complet refuse en plus »
     * sont documentés dans le fichier de cas comme divergence voulue, gardés par les tests
     * d'`OrphanPinGuard` eux-mêmes.
     *
     * @param list<array{id: string, venueId: string, dayOfWeek: int, startTime: string}> $reservations
     * @param list<array{venueId: string, dayOfWeek: int, startTime: string}>             $slots
     *
     * @return list<string> ids des réservations orphelines-par-triplet, ordre d'entrée
     */
    public static function orphanTripletIds(array $reservations, array $slots): array
    {
        $grid = [];
        foreach ($slots as $slot) {
            $grid[self::tripletKey($slot['venueId'], $slot['dayOfWeek'], $slot['startTime'])] = true;
        }

        $orphans = [];
        foreach ($reservations as $reservation) {
            if (!isset($grid[self::tripletKey($reservation['venueId'], $reservation['dayOfWeek'], $reservation['startTime'])])) {
                $orphans[] = $reservation['id'];
            }
        }

        return $orphans;
    }

    /** Heure NORMALISÉE à H:i (comme `hhmm` côté front) — la grille peut porter les secondes. */
    private static function tripletKey(string $venueId, int $dayOfWeek, string $startTime): string
    {
        return $venueId . '|' . $dayOfWeek . '|' . substr($startTime, 0, 5);
    }

    /**
     * Le message à afficher, ou null si tout épinglage retombe sur un créneau existant.
     */
    public function firstOrphanMessage(Schedule $schedule): ?string
    {
        if ($this->schedulePlanProvisioner->isSeasonSchedule($schedule)) {
            return null;
        }
        $schedulePlanId = $schedule->getSchedulePlanId();

        // Les créneaux RÉELLEMENT SERVIS à la période, par (gymnase, jour, heure) — c'est
        // ce qu'un épinglage désigne : ni un verrou ni une réservation ne cite l'id du
        // créneau. On applique le MÊME retrait des jours fermés que buildForOverlay : sans
        // lui, un verrou posé un jour où le gymnase est déclaré fermé passerait le
        // garde-fou et la séance serait perdue en silence — ce que ce garde existe pour
        // empêcher (revue #8).
        $closedWeekdaysByVenue = $this->closedWeekdaysOf($schedule);
        // Un gymnase DÉSACTIVÉ ne sert pas : ses créneaux existent encore en base (le mode
        // conserve la grille) mais buildForOverlay les retire du payload, avec les
        // épinglages qui s'y trouvent. Les compter comme disponibles ici laissait donc
        // passer un verrou que le solveur ne verrait jamais, et la séance était déplacée
        // en silence — précisément ce que ce garde existe pour empêcher (revue #8, round 4).
        $disabledVenueIds = [];
        foreach ($this->entityManager->getRepository(VenuePeriodOverride::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $override) {
            if (VenuePeriodMode::DISABLED === $override->getMode()) {
                $disabledVenueIds[$override->getVenueId()] = true;
            }
        }
        $available = [];
        foreach ($this->entityManager->getRepository(VenueTrainingSlot::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $slot) {
            if (isset($disabledVenueIds[$slot->getVenueId()]) || isset($closedWeekdaysByVenue[$slot->getVenueId()][$slot->getDayOfWeek()])) {
                continue;
            }
            $available[$this->key($slot->getVenueId(), $slot->getDayOfWeek(), $slot->getStartTime()->format('H:i'))] = true;
        }
        // P3-20 (décision fondateur 2026-08-06) — un épinglage sur un gymnase DÉSACTIVÉ
        // ne bloque PLUS. Le mode conserve tout (grille, réservations, verrous) pour que
        // réactiver rende la saisie ; l'écran les masque ; et le solveur ne les verra
        // jamais puisque `buildForOverlay` retire le gymnase du payload. Refuser la
        // génération ici enfermait donc le gestionnaire sur un épinglage devenu
        // INVISIBLE, nommant un gymnase que l'écran ne montre plus — le geste de
        // désactivation fabriquait lui-même l'impasse (P3-20, revue #342).
        //
        // ⚠ La doctrine « on ne fait pas de chose magique » n'est pas relâchée : rien
        // n'est déplacé en silence (le gymnase ne sert pas du tout), et le récap
        // continue d'annoncer le surplus de réservations d'une équipe. Les AUTRES causes
        // d'orphelin — grille vidée, jour de fermeture — bloquent toujours : là, une
        // séance SERAIT déplacée ailleurs sans le dire.

        // Verrous HARD du work-loop de CETTE version. Les placements SOFT/NONE sont des
        // RÉSULTATS de solve, pas des épinglages : les refuser bloquerait toute
        // régénération après un simple changement de grille.
        foreach ($this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $schedule->getId()]) as $lock) {
            if (LockLevel::HARD !== $lock->getLockLevel()) {
                continue;
            }
            if (isset($disabledVenueIds[$lock->getVenueId()])) {
                continue;
            }
            if (!isset($available[$this->key($lock->getVenueId(), $lock->getDayOfWeek(), $lock->getStartTime()->format('H:i'))])) {
                return $this->message($lock->getVenueId(), $lock->getDayOfWeek());
            }
        }

        foreach ($this->entityManager->getRepository(Reservation::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $reservation) {
            if (isset($disabledVenueIds[$reservation->getVenueId()])) {
                continue;
            }
            if (!isset($available[$this->key($reservation->getVenueId(), $reservation->getDayOfWeek(), $reservation->getStartTime()->format('H:i'))])) {
                return $this->message($reservation->getVenueId(), $reservation->getDayOfWeek());
            }
        }

        return null;
    }

    /**
     * Les jours de fermeture effectifs de la période — même source que buildForOverlay
     * (contraintes datées de l'entrée, ou de sa mère pour une semaine enfant).
     *
     * @return array<string, array<int, true>>
     */
    private function closedWeekdaysOf(Schedule $schedule): array
    {
        $entry = $this->entityManager->getRepository(CalendarEntry::class)
            ->findOneBy(['id' => $this->schedulePlanProvisioner->periodEntryIdOf($schedule) ?? '']);
        if (!$entry instanceof CalendarEntry) {
            return [];
        }
        $dated = $this->entityManager->getRepository(Constraint::class)
            ->findBy(['calendarEntryId' => $entry->datedConstraintSourceId()]);

        return VenueClosureDays::closedWeekdaysByVenue($dated, $entry->getStartDate(), $entry->getEndDate());
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
