<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Le moteur n'a pas rendu son verdict `/validate-assignments` DANS LE DÉLAI (rail de retouche
 * move/place). DISTINCT d'un moteur injoignable/cassé (→ 502) : ici le moteur travaille, il est
 * simplement TROP LENT sous le délai transport. Le service la lève en traduisant le
 * `TimeoutExceptionInterface` du client HTTP — RIEN n'a été écrit, aucun verdict n'est inventé.
 *
 * Le contrôleur la mappe en **504 Gateway Timeout** (« l'amont a répondu trop tard », par
 * opposition au 502 « l'amont a mal/pas répondu ») avec le code machine {@see self::CODE} pour que
 * le front NOMME la cause (« la vérification n'a pas abouti — le moteur n'a pas répondu à temps »)
 * et propose de réessayer, au lieu d'afficher un numéro nu.
 */
final class EngineTimeoutException extends RuntimeException
{
    /** Code machine porté au front (patron des codes du rail : target_locked, generation_in_progress…). */
    public const string CODE = 'engine_timeout';

    public function errorCode(): string
    {
        return self::CODE;
    }
}
