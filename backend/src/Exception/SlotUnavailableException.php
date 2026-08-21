<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Levée quand une retouche de planning — créer une séance (place-slot) OU en déplacer une (move) —
 * vise un créneau (venueId + dayOfWeek + startTime) où AUCUNE fenêtre de gymnase n'existe (cible
 * inexistante ou jour fermé, la grille du payload excluant déjà les deux). Sans fenêtre, il n'y a ni
 * durée à résoudre côté serveur ni place à faire juger par le moteur (qui répondrait de toute façon
 * « créneau indisponible ») : on tranche AVANT l'appel. Le contrôleur mappe en 422 `slot_unavailable`,
 * RIEN n'est écrit.
 */
final class SlotUnavailableException extends RuntimeException {}
