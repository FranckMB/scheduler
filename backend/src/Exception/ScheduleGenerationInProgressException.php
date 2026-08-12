<?php

declare(strict_types=1);

namespace App\Exception;

use App\Service\ClubGenerationLock;
use RuntimeException;

/**
 * Levée quand un geste de work-loop (déplacement de créneau) est demandé pendant qu'une
 * génération tourne pour le club. Déplacer à ce moment écraserait un résultat en vol :
 * le contrôleur la mappe en 409. Sonde de lecture : {@see ClubGenerationLock::isGenerating()}.
 */
final class ScheduleGenerationInProgressException extends RuntimeException {}
