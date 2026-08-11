<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Pourquoi un créneau est verrouillé — la réponse que `lockLevel` seul ne donne pas.
 *
 * Un verrou HARD peut venir de deux mondes qu'il faut distinguer pour oser (ou non) y
 * toucher : une RÉSERVATION de gymnase réelle (le créneau est pris, on n'y touche pas) ou
 * un ÉPINGLAGE manuel du gestionnaire (il peut le retirer). Cette colonne accompagne un
 * verrou (lockLevel != NONE) ; sur un créneau non verrouillé elle vaut NULL.
 *
 * - RESERVATION : le créneau est né d'une `Reservation` (pin durable équipe→créneau).
 * - MANUAL      : le gestionnaire l'a verrouillé à la main (work-loop / CRUD management).
 * - UNKNOWN     : verrouillé, mais l'origine est INDÉCIDABLE — jamais une devinette. C'est
 *                 une ignorance assumée, pas une absence de verrou.
 */
enum LockOrigin: string
{
    use HasValues;

    case RESERVATION = 'RESERVATION';
    case MANUAL = 'MANUAL';
    case UNKNOWN = 'UNKNOWN';
}
