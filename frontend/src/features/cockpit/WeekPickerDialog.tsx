import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { Spinner } from "@/shared/components/ui/spinner";

import type { CalendarEntry } from "./api";
import { frDateShort, isWithin, type WeekWindow } from "./lib/date";

interface WeekPickerDialogProps {
  mother: CalendarEntry;
  /** Semaines lun→dim couvrant la fenêtre de la mère, clampées à la saison (weeksCovering). */
  weeks: WeekWindow[];
  busy: boolean;
  /** Semaines cochées → création des plans de semaine. */
  onPickWeeks: (weeks: WeekWindow[]) => void;
  /** Chemin « d'un bloc » : adapter toute la période sur son plan (comportement historique). */
  onAdaptWhole: () => void;
  onClose: () => void;
}

/**
 * P2-5 E1 (fondateur 2026-07-18) : « la semaine est l'unité hors socle ». Adapter
 * une période longue = choisir les SEMAINES à traiter — chaque semaine cochée
 * devient un plan indépendant. Précochées : les semaines que l'événement touche
 * (toutes ici, par construction de weeksCovering). Le chemin « d'un bloc » reste
 * offert (décision fondateur — période courte ou gestionnaire pressé).
 */
export function WeekPickerDialog({ mother, weeks, busy, onPickWeeks, onAdaptWhole, onClose }: WeekPickerDialogProps) {
  const [checked, setChecked] = useState<Set<string>>(new Set(weeks.map((w) => w.monday)));

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

  return (
    <Modal label="Choisir les semaines" title="Quelles semaines ajuster ?" onClose={onClose} className="max-w-md">
      <p className="mt-2 text-sm text-muted-foreground">
        « {mother.title} » couvre plusieurs semaines. Chaque semaine cochée devient un planning indépendant, ajustable à son rythme.
      </p>
      <ul className="mt-4 space-y-2">
        {weeks.map((week) => {
          const touched = isWithin(mother.startDate, week.startDate, week.endDate) || isWithin(week.startDate, mother.startDate, mother.endDate);
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
      <div className="mt-6 flex flex-wrap justify-end gap-2">
        <Button variant="ghost" size="sm" onClick={onAdaptWhole} disabled={busy}>
          Adapter toute la période d'un bloc
        </Button>
        <Button size="sm" onClick={() => onPickWeeks(picked)} disabled={busy || 0 === picked.length}>
          {busy ? <Spinner className="size-4" /> : null}
          Créer {picked.length > 1 ? `les ${picked.length} plannings de semaine` : "le planning de la semaine"}
        </Button>
      </div>
    </Modal>
  );
}
