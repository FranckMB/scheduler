<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\VenueTrainingSlot;
use LogicException;

/**
 * ADR-0002 — QUELLE ANCRE porte « cette ligne appartient-elle à une période ? ».
 *
 * Source UNIQUE du mapping. Depuis les lots C2-C3, trois entités voisines répondent à la
 * même question par DEUX champs différents, et c'est une décision, pas un accident :
 *
 * - `Reservation`, `VenueTrainingSlot` → **`schedulePlanId`**. Ce sont des RÉPONSES (« la
 *   mairie me prête ce gymnase POUR cet ajustement », « je pose cette équipe DANS ce
 *   planning »), et le découpage hebdomadaire (E1) leur demandera de distinguer deux plans
 *   partageant un même déclencheur.
 * - `Constraint` → **`calendarEntryId`**. La contrainte DATÉE décrit le FAIT (« Barros
 *   fermé ») ; le radar de conflits la lit PAR L'ENTRÉE pour annoncer « cette fermeture
 *   gêne 3 séances » — c'est ce qui DÉCLENCHE le geste « ajuster ». L'ancrer au plan la
 *   rendrait illisible tant qu'aucun plan n'existe, or le plan naît de ce geste (décision
 *   fondateur 2026-07-17 ; l'invariant 5 de l'ADR a été corrigé, il se contredisait avec la
 *   section « Rôle de CalendarEntry »).
 *
 * Dans les deux cas, **NULL = la structure PARTAGÉE** (inv. 6) : c'est ce qu'on
 * photographie, ce qu'on transmet à la saison suivante, et ce que le socle génère.
 *
 * Pourquoi une classe plutôt qu'un ternaire recopié : le mapping était dupliqué dans
 * StructureSnapshotter et StructureRestorer, et ce dernier le DEVINAIT — son `else`
 * retombait sur `calendarEntryId` pour toute entité hors liste. Une ancre devinée diverge
 * en silence, et sur une colonne nullable ça ne casse rien : ça fait passer une ligne de
 * période pour une ligne de base. Le planning reste plausible, et devient faux.
 */
final class StructureAnchor
{
    /**
     * Le champ qui porte l'appartenance à une période, pour cette entité.
     *
     * `match` EXHAUSTIF, sans branche par défaut : une entité inconnue lève, elle ne se voit
     * pas attribuer une ancre au hasard. C'est le point du refactor — un `else` qui retombe
     * sur `calendarEntryId` ne casse RIEN sur une colonne nullable : il fait passer les
     * lignes de période pour des lignes de base, le socle hérite de réglages qui ne lui
     * appartiennent pas, et le planning reste plausible. Mieux vaut exploser à l'ajout d'une
     * entité que diverger en silence.
     *
     * @param class-string $entityClass
     */
    public static function of(string $entityClass): string
    {
        return match ($entityClass) {
            Reservation::class, VenueTrainingSlot::class => 'schedulePlanId',
            Constraint::class => 'calendarEntryId',
            default => throw new LogicException(\sprintf('Aucune ancre de période connue pour %s — déclarez-la ici (schedulePlanId si la ligne est une RÉPONSE, calendarEntryId si elle décrit le FAIT).', $entityClass)),
        };
    }
}
