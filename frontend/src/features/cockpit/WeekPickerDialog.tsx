import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Modal } from "@/shared/components/ui/modal";
import { Spinner } from "@/shared/components/ui/spinner";

import { frDateShort, mergeSegments, segmentLabel, segmentsFromOffer, segmentWeekCount, splitSegment, type ExcludedWeekRange, type WeekSegment, type WeekWindow } from "./lib/date";
import type { WeekPickerState, WindowConflict } from "./lib/useWeekAdapt";
import { WindowAlreadyPlannedNotice } from "./WindowAlreadyPlannedNotice";

/** Ce que le picker doit dire de l'état « bloc déjà généré » + la découpe destructive à câbler. */
export interface WeekPickerBlock {
  versionCount: number;
  /** Bloc VALIDÉ (version choisie) : la découpe destructive n'est PAS offerte (chaîne non atomique). */
  validated: boolean;
  /** Une version est EN GÉNÉRATION : la découpe est désactivée avec sa raison. */
  generationInFlight: boolean;
  /** La suppression des versions est en cours. */
  deleting: boolean;
  /** Un échec PARTIEL a laissé des versions : on le dit, on reste dans l'état bloqué. */
  deleteFailed: boolean;
  /** Confirmé : supprime les versions puis laisse le picker rebasculer en « choix des semaines ». */
  onDeleteVersions: () => void;
}

interface WeekPickerDialogProps {
  /** Libellé de la période mère (matérialisée OU vacance pas encore créée — P2-5 E1). */
  title: string;
  /** Fenêtre de la mère (pour segmenter : entame/fin partielles de l'événement). */
  startDate: string;
  endDate: string;
  /** Semaines lun→dim OFFERTES couvrant la fenêtre de la mère, clampées à la saison. */
  weeks: WeekWindow[];
  busy: boolean;
  /**
   * P2-36/P2-40 — état NOMMÉ du picker : `weeks` (choix, l'existant), `loading` (plans/plannings/
   * enfants pas résolus — le dialogue s'ouvre et le DIT au lieu de partir en bloc en silence),
   * `block` (une adaptation d'un bloc porte déjà des versions), `holiday` (une fermeture chevauche
   * des vacances : les semaines sous vacances sont écartées, le chemin d'un bloc disparaît). Défaut
   * `weeks`.
   */
  state?: WeekPickerState;
  /** Requis en état `block` : les faits + la découpe destructive à proposer. */
  block?: WeekPickerBlock;
  /** P2-40 — les blocs de semaines écartés parce qu'une vacance les gouverne (ligne d'info, état `holiday`). */
  excludedRanges?: ExcludedWeekRange[];
  /** P2-41 — segments cochés → un planning (enfant) par segment. */
  onPickSegments: (segments: WeekSegment[]) => void;
  /** Chemin « d'un bloc » : adapter toute la période sur son plan (comportement historique). */
  onAdaptWhole: () => void;
  /**
   * P2-40 — « Consigner l'indisponibilité » : chemin PENDING, état `holiday` sans aucune semaine
   * offerte (100 % sous vacances). Matérialise le FAIT sans plan ni navigation. Absent quand
   * l'entrée existe déjà en base (rien à consigner).
   */
  onRecordOnly?: () => void;
  onClose: () => void;
  /** P2-38 — un refus « une seule planification par fenêtre » sur la création de semaines. */
  conflict?: WindowConflict | null;
  /** Ouvrir le planning en conflit (navigation vers son entrée). */
  onOpenConflict?: (entryId: string) => void;
}

/**
 * P2-41 (fondateur, amende P2-5 E1) : « le SEGMENT est l'unité hors socle ». La liste du picker
 * n'est plus une semaine par ligne mais une liste de SEGMENTS — des blocs de semaines pleines et
 * contiguës proposés aux ruptures GÉOMÉTRIQUES (`segmentsFromOffer` : entame/fin partielle de
 * l'événement, discontinuité de l'offre). Ils sont PRÉCOCHÉS ; le gestionnaire peut SCINDER un
 * segment (le déplier en semaines) ou FUSIONNER des segments adjacents dans l'offre — liberté
 * totale, le serveur ne borne que contiguïté + enveloppe. Chaque segment coché devient un planning.
 *
 * Le front ne redérive AUCUNE règle solveur : les ruptures sont calculées des données servies
 * (semaines offertes + fenêtre de l'événement). La phrase sur un segment multi-semaines est de la
 * PRÉSENTATION (comment le solveur traitera N semaines en un plan), jamais une décision.
 *
 * P2-36 : le dialogue s'OUVRE toujours et NOMME sa raison (`state`) — « en chargement » ≠ « déjà
 * générée d'un bloc » — au lieu de basculer en bloc sans un mot.
 */
export function WeekPickerDialog({ title, startDate, endDate, weeks, busy, state = "weeks", block, excludedRanges = [], onPickSegments, onAdaptWhole, onRecordOnly, onClose, conflict, onOpenConflict }: WeekPickerDialogProps) {
  // Segments dérivés de l'offre + fenêtre, PUIS mutés localement par scinder/fusionner.
  const [segments, setSegments] = useState<WeekSegment[]>(() => segmentsFromOffer(weeks, startDate, endDate));
  const [checked, setChecked] = useState<Set<string>>(() => new Set(segments.map((s) => s.monday)));
  // L'offre change (loading → weeks : de [] à peuplée) : on ré-initialise segments + coches. Motif
  // « ajuster un state quand une prop change » (setState pendant le rendu) plutôt qu'un effet — les
  // gestes scinder/fusionner ne doivent PAS être écrasés à chaque rendu (l'array `weeks` est neuf à
  // chaque fois, seule sa signature est stable).
  const signature = weeks.map((w) => w.monday).join("|");
  const [sig, setSig] = useState(signature);
  if (sig !== signature) {
    const fresh = segmentsFromOffer(weeks, startDate, endDate);
    setSegments(fresh);
    setChecked(new Set(fresh.map((s) => s.monday)));
    setSig(signature);
  }
  // Confirmation de la découpe destructive (état `block`) : réutilise le patron d'avertissement
  // existant (ConfirmDialog destructif) plutôt qu'une deuxième maison du danger.
  const [confirmingSplit, setConfirmingSplit] = useState(false);

  const toggle = (monday: string) =>
    setChecked((prev) => {
      const next = new Set(prev);
      if (next.has(monday)) {
        next.delete(monday);
      } else {
        next.add(monday);
      }
      return next;
    });

  // Scinder un segment multi-semaines : il devient ses semaines individuelles (les coches suivent).
  const splitAt = (index: number) => {
    const seg = segments[index];
    const parts = splitSegment(seg);
    setSegments((prev) => [...prev.slice(0, index), ...parts, ...prev.slice(index + 1)]);
    setChecked((prev) => {
      const next = new Set(prev);
      const was = next.has(seg.monday);
      next.delete(seg.monday);
      if (was) {
        parts.forEach((p) => next.add(p.monday));
      }
      return next;
    });
  };

  // Fusionner un segment avec le PRÉCÉDENT (adjacent dans la liste — même par-dessus une rupture).
  const mergeWithPrevious = (index: number) => {
    const a = segments[index - 1];
    const b = segments[index];
    const merged = mergeSegments(a, b);
    setSegments((prev) => [...prev.slice(0, index - 1), merged, ...prev.slice(index + 1)]);
    setChecked((prev) => {
      const next = new Set(prev);
      const was = next.has(a.monday) || next.has(b.monday);
      next.delete(a.monday);
      next.delete(b.monday);
      if (was) {
        next.add(merged.monday); // merged.monday === a.monday
      }
      return next;
    });
  };

  const picked = segments.filter((s) => checked.has(s.monday));
  const versionCount = block?.versionCount ?? 0;
  const versionLabel = `${versionCount} version${versionCount > 1 ? "s" : ""}`;
  const createLabel = picked.length > 1 ? `Créer les ${picked.length} plannings` : "Créer le planning";

  // La liste de segments, partagée par l'état `weeks` (choix classique) et `holiday` (les segments
  // hors vacances qui restent à traiter). Précochés ; scinder/fusionner sont des gestes nommés,
  // atteignables au clavier.
  const segmentList = (
    <ul className="mt-4 space-y-2">
      {segments.map((seg, index) => {
        const multi = segmentWeekCount(seg) > 1;
        const label = segmentLabel(seg);
        return (
          <li key={seg.monday} className="rounded-md border border-border px-3 py-2 text-sm">
            <div className="flex items-start justify-between gap-2">
              <label className="flex items-center gap-2">
                <input type="checkbox" className="size-4 accent-[var(--accent)]" checked={checked.has(seg.monday)} onChange={() => toggle(seg.monday)} />
                <span>{label}</span>
              </label>
              <div className="flex shrink-0 gap-1">
                {index > 0 ? (
                  <Button variant="ghost" size="sm" disabled={busy} aria-label={`Fusionner « ${label} » avec le segment précédent`} onClick={() => mergeWithPrevious(index)}>
                    Fusionner
                  </Button>
                ) : null}
                {multi ? (
                  <Button variant="ghost" size="sm" disabled={busy} aria-label={`Scinder « ${label} » en semaines`} onClick={() => splitAt(index)}>
                    Scinder
                  </Button>
                ) : null}
              </div>
            </div>
            {/* Pédagogie (présentation, pas décision) sur un segment multi-semaines : comment le
                solveur traite N semaines en UN plan. */}
            {multi ? (
              <p className="mt-1 text-xs text-muted-foreground">Des semaines identiques donnent un planning exact ; si elles diffèrent, la fermeture la plus large s'applique à toutes.</p>
            ) : null}
          </li>
        );
      })}
    </ul>
  );

  return (
    <Modal label="Choisir les semaines" title="Quelles semaines ajuster ?" onClose={onClose} className="max-w-md">
      {"weeks" === state ? (
        <>
          <p className="mt-2 text-sm text-muted-foreground">« {title} » couvre plusieurs semaines. Chaque segment coché devient un planning indépendant — scindez-le en semaines ou fusionnez des segments voisins à votre main.</p>
          {segmentList}
        </>
      ) : null}

      {/* ÉTAT « chevauchement vacances » (P2-40) : les semaines sous vacances sont EXCLUES (pas
          grisées) — le rappel vit déjà dans le planning des vacances. Une ligne d'info le dit, et
          le chemin « d'un bloc » disparaît (un plan de bloc gouvernerait la fenêtre des vacances).
          Reste (s'il en reste) le choix des segments HORS vacances ; 100 % couvert → info seule. */}
      {"holiday" === state ? (
        <div className="mt-2 space-y-3 text-sm">
          {excludedRanges.map((range) => (
            <p key={range.startDate} className="rounded-md border border-amber-400/50 bg-amber-400/10 px-3 py-2 text-foreground">
              Semaines du {frDateShort(range.startDate)} au {frDateShort(range.endDate)} couvertes par {range.labels.join(", ")} — le rappel vous attend dans son planning.
            </p>
          ))}
          {segments.length > 0 ? (
            <>
              <p className="text-muted-foreground">Choisissez les semaines à ajuster, hors vacances. Chaque segment coché devient un planning indépendant.</p>
              {segmentList}
            </>
          ) : (
            <p className="text-muted-foreground">Toutes les semaines de cette indisponibilité sont couvertes par des vacances — il n'y a rien à ajuster en dehors.</p>
          )}
        </div>
      ) : null}

      {/* ÉTAT « chargement » : on ne connaît pas encore l'état des plans/plannings/enfants — le
          dialogue le DIT (au lieu de partir en bloc en silence) ; il ne prétend PAS que le choix
          des semaines n'existe pas. Le chemin « d'un bloc » reste offert, il marche toujours. */}
      {"loading" === state ? (
        <div className="mt-2 flex items-start gap-2 text-sm text-muted-foreground">
          <Spinner className="mt-0.5 size-4 shrink-0" />
          <p>On vérifie l'état de « {title} »… Le choix « semaine par semaine ou d'un bloc » s'affiche dès que c'est chargé.</p>
        </div>
      ) : null}

      {/* ÉTAT « déjà générée d'un bloc » : NOMMER le fait (≠ « en chargement », ≠ « une seule
          semaine ») — un message générique recréerait le défaut. « Continuer d'un bloc » ne se
          perd jamais ; la découpe destructive n'apparaît QUE si le bloc n'est pas validé. */}
      {"block" === state ? (
        <div className="mt-2 space-y-3 text-sm">
          <p className="text-muted-foreground">
            « {title} » a déjà été adaptée d'un bloc — {versionLabel}. Continuez sur ce planning, ou repartez de zéro en le découpant en semaines.
          </p>
          {block?.validated ? (
            // Décision fondateur : bloc VALIDÉ → pas de découpe destructive ici (la chaîne
            // rouvrir→supprimer n'est pas atomique, un échec après réouverture laisserait un
            // planning validé dépointé). On renvoie vers les gestes qui existent.
            <p className="rounded-md border border-border bg-muted/40 px-3 py-2 text-muted-foreground">
              Ce planning de bloc est validé. Pour le découper en semaines, rouvrez-le puis supprimez-le d'abord (depuis « Voir le planning »), puis revenez adapter.
            </p>
          ) : (
            <>
              {block?.deleteFailed ? (
                <p role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-foreground">
                  Certaines versions n'ont pas pu être supprimées — réessayez.
                </p>
              ) : null}
              <div>
                <Button
                  variant="destructive"
                  size="sm"
                  disabled={busy || block?.deleting || block?.generationInFlight}
                  title={block?.generationInFlight ? "Une génération est en cours — attendez qu'elle finisse." : undefined}
                  onClick={() => setConfirmingSplit(true)}
                >
                  {block?.deleting ? <Spinner className="size-4" /> : null}
                  Supprimer les versions et découper en semaines
                </Button>
                {block?.generationInFlight ? <p className="mt-1 text-xs text-muted-foreground">Une génération est en cours — la découpe sera possible ensuite.</p> : null}
              </div>
            </>
          )}
        </div>
      ) : null}

      {conflict && onOpenConflict ? (
        <div className="mt-4">
          <WindowAlreadyPlannedNotice message={conflict.message} onOpen={() => onOpenConflict(conflict.entryId)} />
        </div>
      ) : null}

      <div className="mt-6 flex flex-wrap justify-end gap-2">
        {/* Le chemin « d'un bloc » disparaît dès qu'une vacance couvre une semaine (état holiday). */}
        {"holiday" === state ? null : (
          <Button variant="ghost" size="sm" onClick={onAdaptWhole} disabled={busy || block?.deleting}>
            {"block" === state ? "Continuer d'un bloc" : "Adapter toute la période d'un bloc"}
          </Button>
        )}
        {"weeks" === state || ("holiday" === state && segments.length > 0) ? (
          <Button size="sm" onClick={() => onPickSegments(picked)} disabled={busy || 0 === picked.length}>
            {busy ? <Spinner className="size-4" /> : null}
            {createLabel}
          </Button>
        ) : null}
        {/* 100 % sous vacances, chemin pending : consigner le FAIT (sans plan ni navigation). */}
        {"holiday" === state && 0 === segments.length && undefined !== onRecordOnly ? (
          <Button size="sm" onClick={onRecordOnly} disabled={busy}>
            {busy ? <Spinner className="size-4" /> : null}
            Consigner l'indisponibilité
          </Button>
        ) : null}
      </div>

      {/* Confirmation destructive : NOMME la portée (nombre de versions + réglages qui repartent
          de la saison — ce que fait la découpe côté serveur). Patron ConfirmDialog réutilisé. */}
      {block ? (
        <ConfirmDialog
          open={confirmingSplit}
          title="Découper cette période en semaines ?"
          description={
            <>
              Cela supprime {versionLabel} déjà générée{versionCount > 1 ? "s" : ""} d'un bloc pour « {title} », puis permet de la découper. Les réglages de cette période repartiront de la saison. Action définitive.
            </>
          }
          confirmLabel="Supprimer et découper"
          destructive
          onConfirm={() => {
            setConfirmingSplit(false);
            block.onDeleteVersions();
          }}
          onCancel={() => setConfirmingSplit(false)}
        />
      ) : null}
    </Modal>
  );
}
