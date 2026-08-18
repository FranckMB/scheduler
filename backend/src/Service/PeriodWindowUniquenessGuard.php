<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\WindowAlreadyPlannedException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ADR-0002 inv. 4 (amendement P2-38, 2026-08-18) — UNE SEULE PLANIFICATION PAR FENÊTRE.
 *
 * Deux plans de période ne doivent jamais gouverner les mêmes dates. Cette garde s'appelle à
 * la NAISSANCE d'un plan de période (le geste « Adapter » et la création d'une entrée-SEMAINE
 * qui naît avec son plan — les deux seuls sites de naissance) : si un AUTRE plan gouverne tout
 * ou partie de la fenêtre qui naît, on refuse (409 `window_already_planned`) en NOMMANT le plan
 * déjà en place. On ne rétrécit ni ne supprime rien : le geste destructif reste au gestionnaire
 * (le message l'invite à modifier ou supprimer le planning existant, ou à découper la période).
 *
 * ⚠ Jamais posée à la création d'une entrée RACINE : déclarer une fermeture par-dessus des
 * vacances (le FAIT, sans plan) doit rester libre — c'est le PLAN d'adaptation qu'on borne.
 *
 * Les deux chevauchements LÉGITIMES qui ne déclenchent PAS la garde sont capturés par un seul
 * prédicat, l'ANCÊTRE RACINE `COALESCE(parent_entry_id, id)` (hiérarchie à un seul niveau, P2-5) :
 *  - parent ↔ enfant : une semaine vit DANS sa mère — même racine, exclue ;
 *  - semaines sœurs : même mère — même racine, exclue (leur non-chevauchement mutuel est déjà
 *    gardé à part par CalendarEntryStateProcessor::assertValidWeekChild, qu'on ne double pas).
 * Deux périodes RACINES distinctes ont des racines différentes : elles, se voient.
 *
 * Comparaison sur les colonnes DATE de `calendar_entry` (jamais les timestamptz du plan) : la
 * fenêtre du plan est GELÉE égale à celle de son entrée (inv. C1), et une comparaison DATE est
 * exacte au jour près, sans ambiguïté de fuseau. SQL brut : `season_filter` épingle les lectures
 * ORM à la saison active, or un plan peut vivre pour une autre saison ; RLS scope le club.
 */
final class PeriodWindowUniquenessGuard
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /**
     * @param string $clubId          le club du plan qui naît
     * @param string $seasonId        sa saison (deux périodes de saisons différentes ne se croisent jamais)
     * @param string $bornRootEntryId l'ancêtre racine de l'entrée qui naît : son parentEntryId, sinon son id
     * @param string $bornStart       la borne basse de la fenêtre qui naît (Y-m-d)
     * @param string $bornEnd         la borne haute de la fenêtre qui naît (Y-m-d)
     *
     * @throws WindowAlreadyPlannedException un autre plan de période gouverne tout ou partie de la fenêtre
     */
    public function assertWindowFree(string $clubId, string $seasonId, string $bornRootEntryId, string $bornStart, string $bornEnd): void
    {
        $conflict = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT e.id AS entry_id, e.title AS entry_title, e.start_date, e.end_date '
            . 'FROM schedule_plan p JOIN calendar_entry e ON e.id = p.calendar_entry_id '
            . 'WHERE p.club_id = :club AND p.season_id = :season AND p.type <> \'SEASON\' '
            // Chevauchement (inclusion OU recouvrement partiel) : début ≤ fin de l'autre ET fin ≥ début.
            . 'AND e.start_date <= :bornEnd AND e.end_date >= :bornStart '
            // La FAMILLE (même ancêtre racine) est exclue : parent↔enfant et semaines sœurs sont légitimes.
            . 'AND COALESCE(e.parent_entry_id, e.id) <> :root '
            . 'ORDER BY e.start_date ASC LIMIT 1',
            [
                'club' => $clubId,
                'season' => $seasonId,
                'bornStart' => $bornStart,
                'bornEnd' => $bornEnd,
                'root' => $bornRootEntryId,
            ],
        );

        if (false === $conflict) {
            return;
        }

        $conflictEntryId = (string) $conflict['entry_id'];
        $windowLabel = $this->schedulePlanProvisioner->windowLabel(
            new DateTimeImmutable((string) $conflict['start_date']),
            new DateTimeImmutable((string) $conflict['end_date']),
        );

        // NOMME la période telle que le gestionnaire l'a INTITULÉE (pas le nom auto-généré du
        // plan, qui répète déjà la fenêtre), + sa fenêtre, et invite aux DEUX issues explicites (modifier ou
        // supprimer le planning en place) plus la découpe en semaines — geste déjà existant (P2-36).
        // Aucun identifiant interne (gardé par PublicTextIsFreeOfInternalIdentifiersTest).
        throw new WindowAlreadyPlannedException($conflictEntryId, \sprintf('Ces dates sont déjà planifiées par « %s » (%s). Une seule planification peut gouverner une même période : modifiez ce planning existant ou supprimez-le avant d’en créer un autre ici. Vous pouvez aussi découper la période en semaines pour les planifier séparément.', (string) $conflict['entry_title'], $windowLabel));
    }
}
