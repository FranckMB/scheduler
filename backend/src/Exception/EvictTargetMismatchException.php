<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Levée quand le créneau à évincer, désigné par `evictSlotId` sur un déplacement, ne peut PAS
 * être la cible légitime de l'éviction : introuvable, appartenant à un autre planning, égal à
 * la source, ou ne siégeant pas là où le candidat atterrit (même gymnase + même jour +
 * chevauchement horaire). Le contrôleur la mappe en 422 `evict_target_mismatch` — RIEN n'est
 * écrit ni supprimé, le moteur n'est pas appelé.
 */
final class EvictTargetMismatchException extends RuntimeException {}
