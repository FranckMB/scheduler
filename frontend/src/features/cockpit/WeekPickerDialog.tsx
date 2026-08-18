import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Modal } from "@/shared/components/ui/modal";
import { Spinner } from "@/shared/components/ui/spinner";

import { frDateShort, isWithin, type ExcludedWeekRange, type WeekWindow } from "./lib/date";
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
  /** Fenêtre de la mère (pour marquer les semaines « hors événement »). */
  startDate: string;
  endDate: string;
  /** Semaines lun→dim couvrant la fenêtre de la mère, clampées à la saison (weeksCovering). */
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
  /** Semaines cochées → création des plans de semaine. */
  onPickWeeks: (weeks: WeekWindow[]) => void;
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
 * P2-5 E1 (fondateur 2026-07-18) : « la semaine est l'unité hors socle ». Adapter
 * une période longue = choisir les SEMAINES à traiter — chaque semaine cochée
 * devient un plan indépendant. Précochées : les semaines que l'événement touche
 * (toutes ici, par construction de weeksCovering). Le chemin « d'un bloc » reste
 * offert (décision fondateur — période courte ou gestionnaire pressé).
 *
 * P2-36 : le dialogue s'OUVRE toujours, même quand le choix des semaines n'est pas
 * (encore) possible — il nomme alors la raison (`state`), au lieu de basculer en bloc
 * sans un mot. Chaque raison est distincte : « en chargement » ≠ « déjà générée d'un
 * bloc » — un message générique recréerait le défaut.
 */
export function WeekPickerDialog({ title, startDate, endDate, weeks, busy, state = "weeks", block, excludedRanges = [], onPickWeeks, onAdaptWhole, onRecordOnly, onClose, conflict, onOpenConflict }: WeekPickerDialogProps) {
  const [checked, setChecked] = useState<Set<string>>(new Set(weeks.map((w) => w.monday)));
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

  const picked = weeks.filter((w) => checked.has(w.monday));
  const versionCount = block?.versionCount ?? 0;
  const versionLabel = `${versionCount} version${versionCount > 1 ? "s" : ""}`;
  // La liste à cocher, partagée par l'état `weeks` (choix classique) et `holiday` (les semaines
  // hors vacances qui restent à traiter).
  const checkboxList = (
    <ul className="mt-4 space-y-2">
      {weeks.map((week) => {
        const touched = isWithin(startDate, week.startDate, week.endDate) || isWithin(week.startDate, startDate, endDate);
        return (
          <li key={week.monday}>
            <label className="flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm">
              <input type="checkbox" className="size-4 accent-[var(--accent)]" checked={checked.has(week.monday)} onChange={() => toggle(week.monday)} />
              <span>
                Semaine du {frDateShort(week.startDate)} au {frDateShort(week.endDate)}
                {touched ? null : <span className="text-muted-foreground"> · hors événement</span>}
              </span>
            </label>
          </li>
        );
      })}
    </ul>
  );

  return (
    <Modal label="Choisir les semaines" title="Quelles semaines ajuster ?" onClose={onClose} className="max-w-md">
      {"weeks" === state ? (
        <>
          <p className="mt-2 text-sm text-muted-foreground">« {title} » couvre plusieurs semaines. Chaque semaine cochée devient un planning indépendant, ajustable à son rythme.</p>
          {checkboxList}
        </>
      ) : null}

      {/* ÉTAT « chevauchement vacances » (P2-40) : les semaines sous vacances sont EXCLUES (pas
          grisées) — le rappel vit déjà dans le planning des vacances. Une ligne d'info le dit, et
          le chemin « d'un bloc » disparaît (un plan de bloc gouvernerait la fenêtre des vacances).
          Reste (s'il en reste) le choix des semaines HORS vacances ; 100 % couvert → info seule. */}
      {"holiday" === state ? (
        <div className="mt-2 space-y-3 text-sm">
          {excludedRanges.map((range) => (
            <p key={range.startDate} className="rounded-md border border-amber-400/50 bg-amber-400/10 px-3 py-2 text-foreground">
              Semaines du {frDateShort(range.startDate)} au {frDateShort(range.endDate)} couvertes par {range.labels.join(", ")} — le rappel vous attend dans son planning.
            </p>
          ))}
          {weeks.length > 0 ? (
            <>
              <p className="text-muted-foreground">Choisissez les semaines à ajuster, hors vacances. Chaque semaine cochée devient un planning indépendant.</p>
              {checkboxList}
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
        {"weeks" === state || ("holiday" === state && weeks.length > 0) ? (
          <Button size="sm" onClick={() => onPickWeeks(picked)} disabled={busy || 0 === picked.length}>
            {busy ? <Spinner className="size-4" /> : null}
            Créer {picked.length > 1 ? `les ${picked.length} plannings de semaine` : "le planning de la semaine"}
          </Button>
        ) : null}
        {/* 100 % sous vacances, chemin pending : consigner le FAIT (sans plan ni navigation). */}
        {"holiday" === state && 0 === weeks.length && undefined !== onRecordOnly ? (
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
