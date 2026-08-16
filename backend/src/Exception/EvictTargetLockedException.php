<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Levée quand le créneau à évincer est verrouillé (lockLevel ≠ NONE, toute origine). D3 : un
 * verrou est souverain — on n'évince jamais un créneau épinglé ou réservé sans le déverrouiller
 * d'abord. Le contrôleur la mappe en 422 `target_locked` avec un message pour le gestionnaire ;
 * le moteur n'est JAMAIS appelé, RIEN n'est écrit ni supprimé.
 */
final class EvictTargetLockedException extends RuntimeException {}
