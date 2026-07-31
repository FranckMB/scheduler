<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\Reservation;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Enum\TeamCoachRole;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-9 PR B — « ce verrou met-il un coach à deux endroits en même temps ? ».
 *
 * Un verrou HARD (une {@see Reservation}) est pré-placé HORS du solveur : sa variable
 * n'est jamais créée, donc aucune contrainte ne peut l'atteindre (ALIGN-07 — le verrou
 * est SOUVERAIN, décision fondateur). Le solveur ne peut donc pas refuser deux verrous
 * qui dédoublent un coach : il ne les voit même pas. Cette impossibilité PHYSIQUE
 * n'est pas une préférence bafouée — le gestionnaire n'a jamais choisi que son coach se
 * dédouble — d'où la décision fondateur : **empêcher la génération**.
 *
 * SOURCE UNIQUE de la règle. Le récap la consomme pour BLOQUER (PR B) et la modale de
 * réservation la consommera pour PRÉVENIR au clic (PR C). Une règle recopiée à la main
 * diverge : c'est ce motif exact qui a coûté trois rounds de revue au volet capacité.
 * D'où la signature ci-dessous, qui prend une LISTE DE RÉSERVATIONS et non un périmètre
 * à résoudre : la modale pourra lui passer les réservations existantes PLUS celle qu'on
 * est en train de poser, sans rien réécrire.
 *
 * Les trois règles (tranchées par le fondateur, 2026-07-31) :
 *  1. **Intervalles réels** `[début, début + durée[` — pas l'égalité des heures de début :
 *     17h00-18h30 et 17h30-19h00 dédoublent bien le coach.
 *  2. **Gymnases DIFFÉRENTS uniquement.** Deux équipes sur le même créneau du même
 *     gymnase divisible, c'est la MUTUALISATION voulue (`Venue.canSplit` + capacité) —
 *     bloquer dessus casserait une fonctionnalité.
 *  3. **Coachs `MAIN` uniquement.** Le solveur ne traite qu'eux en ressource exclusive
 *     (`add_coach_at_most_one`) ; un `ASSISTANT` est optionnel, bloquer dessus
 *     refuserait des réservations légitimes.
 *
 * Rend des données STRUCTURÉES, jamais des phrases : le récap et la modale formulent
 * différemment (« Maxime se trouve à deux créneaux en même temps » vs « Emerick coache
 * déjà les U15F à 17h à Debarros »). Une seule règle, deux rendus.
 */
final class CoachDoubleBookingDetector
{
    /** ISO-8601 : 1 = lundi. */
    private const DAY_NAMES = [
        1 => 'lundi',
        2 => 'mardi',
        3 => 'mercredi',
        4 => 'jeudi',
        5 => 'vendredi',
        6 => 'samedi',
        7 => 'dimanche',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param list<Reservation> $reservations le périmètre EXACT qui partira au solveur
     *                                        (socle : `schedulePlanId IS NULL` ; période :
     *                                        son plan, gymnases désactivés déjà retirés)
     *
     * @return list<array{coachId: string, coachName: string, dayOfWeek: int, first: array{teamName: string, venueName: string, startTime: string}, second: array{teamName: string, venueName: string, startTime: string}}>
     */
    public function detect(array $reservations, string $clubId, string $seasonId): array
    {
        if (\count($reservations) < 2) {
            return []; // il faut deux verrous pour se dédoubler
        }

        $mainCoachByTeam = $this->mainCoachByTeam($clubId, $seasonId);
        // Une équipe sans coach MAIN ne peut pas dédoubler qui que ce soit : on la sort
        // AVANT le balayage plutôt que de la tester à chaque paire.
        $relevant = array_values(array_filter(
            $reservations,
            static fn (Reservation $r): bool => isset($mainCoachByTeam[$r->getTeamId()]),
        ));

        $names = $this->displayNames($relevant, $mainCoachByTeam);
        $conflicts = [];

        foreach ($relevant as $i => $first) {
            foreach (\array_slice($relevant, $i + 1) as $second) {
                if (!$this->collide($first, $second, $mainCoachByTeam)) {
                    continue;
                }

                $coachId = $mainCoachByTeam[$first->getTeamId()];
                $conflicts[] = [
                    'coachId' => $coachId,
                    'coachName' => $names['coach'][$coachId] ?? 'Ce coach',
                    'dayOfWeek' => $first->getDayOfWeek(),
                    'first' => $this->describe($first, $names),
                    'second' => $this->describe($second, $names),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Le message du récap, au gabarit donné par le fondateur. Il doit dire QUELLE
     * réservation retirer : une erreur bloquante sans recours actionnable enferme le
     * gestionnaire.
     *
     * @param array{coachId: string, coachName: string, dayOfWeek: int, first: array{teamName: string, venueName: string, startTime: string}, second: array{teamName: string, venueName: string, startTime: string}} $conflict
     */
    public function describeForRecap(array $conflict): string
    {
        return \sprintf(
            '%s se trouve à deux créneaux en même temps : %s à %s le %s à %s, et %s à %s à %s. Veuillez corriger vos réservations.',
            $conflict['coachName'],
            $conflict['first']['teamName'],
            $conflict['first']['venueName'],
            self::DAY_NAMES[$conflict['dayOfWeek']] ?? 'ce jour',
            $conflict['first']['startTime'],
            $conflict['second']['teamName'],
            $conflict['second']['venueName'],
            $conflict['second']['startTime'],
        );
    }

    /**
     * @param array<string, string> $mainCoachByTeam
     */
    private function collide(Reservation $first, Reservation $second, array $mainCoachByTeam): bool
    {
        // Règle 3 : même coach MAIN des deux côtés, sinon aucun dédoublement.
        if ($mainCoachByTeam[$first->getTeamId()] !== $mainCoachByTeam[$second->getTeamId()]) {
            return false;
        }
        // La même équipe deux fois n'est pas un dédoublement de COACH (c'est une
        // double-réservation d'équipe — autre sujet, hors P2-9 PR B).
        if ($first->getTeamId() === $second->getTeamId()) {
            return false;
        }
        // Règle 2 : même gymnase = mutualisation possible (canSplit + capacité), jamais
        // une impossibilité physique. Le coach y est présent une seule fois.
        if ($first->getVenueId() === $second->getVenueId()) {
            return false;
        }
        if ($first->getDayOfWeek() !== $second->getDayOfWeek()) {
            return false;
        }

        // Règle 1 : intervalles réels, demi-ouverts — deux créneaux qui se touchent
        // (fin de l'un = début de l'autre) ne se chevauchent PAS.
        $startA = (int) $first->getStartTime()->format('H') * 60 + (int) $first->getStartTime()->format('i');
        $startB = (int) $second->getStartTime()->format('H') * 60 + (int) $second->getStartTime()->format('i');

        return $startA < $startB + $second->getDurationMinutes()
            && $startB < $startA + $first->getDurationMinutes();
    }

    /**
     * @param list<Reservation>     $reservations
     * @param array<string, string> $mainCoachByTeam
     *
     * @return array{coach: array<string, string>, team: array<string, string>, venue: array<string, string>}
     */
    private function displayNames(array $reservations, array $mainCoachByTeam): array
    {
        $teamIds = array_values(array_unique(array_map(static fn (Reservation $r): string => $r->getTeamId(), $reservations)));
        $venueIds = array_values(array_unique(array_map(static fn (Reservation $r): string => $r->getVenueId(), $reservations)));
        $coachIds = array_values(array_unique(array_intersect_key($mainCoachByTeam, array_flip($teamIds))));

        $coach = [];
        foreach ([] === $coachIds ? [] : $this->entityManager->getRepository(Coach::class)->findBy(['id' => $coachIds]) as $entity) {
            $coach[$entity->getId()] = trim($entity->getFirstName() . ' ' . $entity->getLastName());
        }
        $team = [];
        foreach ([] === $teamIds ? [] : $this->entityManager->getRepository(Team::class)->findBy(['id' => $teamIds]) as $entity) {
            $team[$entity->getId()] = $entity->getName();
        }
        $venue = [];
        foreach ([] === $venueIds ? [] : $this->entityManager->getRepository(Venue::class)->findBy(['id' => $venueIds]) as $entity) {
            $venue[$entity->getId()] = $entity->getName();
        }

        return ['coach' => $coach, 'team' => $team, 'venue' => $venue];
    }

    /**
     * @param array{coach: array<string, string>, team: array<string, string>, venue: array<string, string>} $names
     *
     * @return array{teamName: string, venueName: string, startTime: string}
     */
    private function describe(Reservation $reservation, array $names): array
    {
        return [
            'teamName' => $names['team'][$reservation->getTeamId()] ?? 'une équipe',
            'venueName' => $names['venue'][$reservation->getVenueId()] ?? 'un gymnase',
            'startTime' => $reservation->getStartTime()->format('H\hi'),
        ];
    }

    /**
     * @return array<string, string> teamId => coachId du coach MAIN
     */
    private function mainCoachByTeam(string $clubId, string $seasonId): array
    {
        $map = [];
        foreach ($this->entityManager->getRepository(TeamCoach::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $link) {
            if (TeamCoachRole::MAIN === $link->getRole()) {
                $map[$link->getTeamId()] = $link->getCoachId();
            }
        }

        return $map;
    }
}
