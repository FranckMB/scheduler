<?php

declare(strict_types=1);

namespace App\Exception;

use App\Service\DemoClubMaterializer;
use RuntimeException;

/**
 * Levée par {@see DemoClubMaterializer::teardownPreviousDemo()} quand le club
 * précédent de l'animateur démo NE PEUT PAS être détruit sans risque : soit ce n'est pas un club
 * démo (`!isDemo()`), soit c'est un club démo PARTAGÉ (il porte un membre autre que l'animateur —
 * cas « Démo Basket Club » ARA9999999 tenu par `demo-bccl@…`). Dans les deux cas RIEN n'est
 * détruit : le raccourci démo ne doit jamais effacer un vrai club ni un club de démo commun.
 *
 * Le contrôleur la mappe en **409 Conflict** — le même verdict que la garde ARA — pour que le
 * front affiche sa bannière « on ne détruit pas un club réel » plutôt qu'un 500 muet.
 */
final class DemoTeardownRefusedException extends RuntimeException {}
