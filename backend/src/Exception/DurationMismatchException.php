<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Levée quand une création de séance (place-slot) fournit un `durationMinutes` qui CONTREDIT la
 * durée de la fenêtre de gymnase visée. La durée d'une séance n'est jamais celle que le client
 * déclare : c'est celle de la fenêtre (venueId + dayOfWeek + startTime), lue dans le MÊME payload
 * que le moteur. Le champ du client n'est donc qu'une ASSERTION — s'il ment, le contrôleur mappe
 * en 422 `duration_mismatch`, RIEN n'est écrit et le moteur n'est jamais appelé.
 */
final class DurationMismatchException extends RuntimeException {}
