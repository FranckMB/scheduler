<?php

declare(strict_types=1);

namespace App\Exception;

use App\EventListener\WindowAlreadyPlannedListener;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ADR-0002 inv. 4 (amendement P2-38, 2026-08-18) — UNE SEULE PLANIFICATION PAR FENÊTRE.
 *
 * Levée à la NAISSANCE d'un plan de période (les deux seuls sites : le geste « Adapter »
 * — POST /api/schedule_plans — et la création d'une entrée-SEMAINE qui naît avec son plan)
 * quand un AUTRE plan de période gouverne déjà tout ou partie de la même fenêtre. Deux plans
 * ne doivent jamais gouverner les mêmes dates (« un overlay d'incident ne touche jamais une
 * semaine de vacances ») : on refuse dans les deux sens, en NOMMANT le plan déjà en place —
 * jamais de destruction ni de rétrécissement automatique, le geste destructif reste au
 * gestionnaire.
 *
 * Le FAIT (la contrainte datée `venue_closed`) reste déclarable librement, sans garde : c'est
 * le PLAN d'adaptation qu'on borne, pas l'indisponibilité.
 *
 * HttpException(409) : Sentry ignore les 4xx (résultat métier attendu, pas une alerte), et le
 * statut est intrinsèque à l'exception. La CHARGE STRUCTURÉE (code machine `window_already_planned`
 * + l'identifiant de l'entrée en conflit, pour que le front y navigue) est composée par
 * {@see WindowAlreadyPlannedListener} — un processor ne rend sinon que
 * `{detail, status}`.
 */
final class WindowAlreadyPlannedException extends HttpException
{
    public function __construct(
        public readonly string $conflictingEntryId,
        string $message,
    ) {
        parent::__construct(409, $message);
    }
}
