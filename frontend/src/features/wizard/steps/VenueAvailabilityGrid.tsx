import { formatDuration } from "@/shared/lib/duration";
import { cn } from "@/shared/lib/utils";

import type { Venue, VenueTrainingSlot } from "../api";
import { fmtMinutes as fmt, hhmm, toMinutes as startMinutes } from "../lib/days";
import { END_MIN, gridTemplateColumns, ROW_H, START_MIN, STEP, WEEK } from "../lib/weekGrid";

interface Props {
  venue: Venue;
  slots: VenueTrainingSlot[];
  selectedSlotId: string | null;
  onAdd: (dayOfWeek: number, startTime: string) => void;
  onSelect: (slot: VenueTrainingSlot) => void;
}

export function VenueAvailabilityGrid({ venue, slots, selectedSlotId, onAdd, onSelect }: Props) {
  const color = venue.color ?? "var(--accent)";

  // La plage verticale est l'UNION de la plage de saisie (08h→23h) et de ce que les
  // créneaux occupent réellement.
  //
  // ⚠ Deux tentatives ratées avant celle-ci, gardées en mémoire parce qu'elles disent ce
  // qu'il ne faut PAS faire. (1) Laisser la ligne se calculer librement : un créneau à
  // 07:00 donnait `grid-row: -2`, que CSS compte depuis la FIN de la grille — le bloc
  // s'affichait à 22:45 sous le libellé « 07:00 ». (2) BORNER la ligne : le bloc venait
  // alors se poser sur la première ligne, recouvrant quatre cellules vides qui ne
  // répondaient plus au clic, et masquant purement et simplement un créneau légitime de
  // 08:00 rendu avant lui — un créneau qui part au solveur et devient inatteignable à
  // l'écran. Borner déplace le mensonge, il ne l'enlève pas.
  //
  // Étendre la grille est la seule réponse qui ne cache ni ne déplace rien — et c'est déjà
  // ce que fait `ReservationGrid`, dont la plage suit ses données.
  const starts = slots.map((s) => startMinutes(s.startTime));
  const ends = slots.map((s) => startMinutes(s.startTime) + s.durationMinutes);
  const gridStart = Math.min(START_MIN, ...starts.map((m) => Math.floor(m / 60) * 60));
  const gridEnd = Math.max(END_MIN, ...ends.map((m) => Math.ceil(m / 60) * 60));
  const rows = Array.from({ length: (gridEnd - gridStart) / STEP }, (_, i) => gridStart + i * STEP);
  const gridTemplateRows = `1.5rem repeat(${rows.length}, ${ROW_H}px)`;

  return (
    // P4-37 — la grille DÉFILE en interne au lieu de pousser la page. Elle n'avait aucune
    // borne verticale : passer de 22h à 23h l'aurait allongée d'autant, aggravant un
    // problème qui existait déjà. `max-h` + `overflow-y-auto` la bornent au viewport.
    <div className="max-h-[min(70vh,40rem)] overflow-auto rounded-lg border border-border bg-card">
      <div className="grid text-xs" style={{ gridTemplateColumns, gridTemplateRows }}>
        {/* Coin figé sur les DEUX axes : il masque la gouttière quand elle défile sous
            l'en-tête, sinon les heures passeraient par-dessus les noms de jours. */}
        <div className="sticky left-0 top-0 z-40 border-b border-r border-border bg-card" style={{ gridColumn: 1, gridRow: 1 }} />
        {WEEK.map((d, i) => (
          <div key={d.n} className="sticky top-0 z-30 border-b border-l border-border bg-card py-0.5 text-center font-medium" style={{ gridColumn: 2 + i, gridRow: 1 }}>
            {d.label}
          </div>
        ))}

        {/* Time gutter — label on the hour */}
        {rows.map((m, i) => (
          // Empilement STRICT, sans égalité : créneaux (z-10) < gouttière (z-20) <
          // en-têtes de jours (z-30) < coin (z-40). À égalité, c'est l'ordre du DOM qui
          // tranche — la gouttière était à z-10 comme les créneaux, rendus APRÈS elle,
          // donc ils repeignaient par-dessus les heures pendant le défilement horizontal
          // que la 7ᵉ colonne rend maintenant courant sur petit écran.
          // La gouttière reste lisible pendant le défilement HORIZONTAL : sans `sticky`,
          // les heures disparaissaient sous les colonnes dès qu'on faisait défiler.
          <div key={`t${m}`} className="sticky left-0 z-20 border-r border-border bg-card pr-1 text-right text-[10px] text-muted-foreground" style={{ gridColumn: 1, gridRow: 2 + i }}>
            {0 === m % 60 ? fmt(m) : ""}
          </div>
        ))}

        {/* Empty clickable cells */}
        {WEEK.map((d, di) =>
          rows.map((m, ri) => (
            <button
              key={`c${d.n}-${m}`}
              type="button"
              aria-label={`${d.label} ${fmt(m)}`}
              onClick={() => onAdd(d.n, fmt(m))}
              className={cn("border-l border-t border-border/40 hover:bg-muted", 0 === m % 60 ? "border-t-border/70" : "")}
              style={{ gridColumn: 2 + di, gridRow: 2 + ri }}
            />
          )),
        )}

        {/* Slots */}
        {slots.map((slot) => {
          const di = WEEK.findIndex((d) => d.n === slot.dayOfWeek);
          if (di < 0) {
            return null;
          }
          // La grille couvrant toute la plage occupée, la ligne et la hauteur se calculent
          // sans borne : le bloc tombe où il est et mesure ce qu'il dure.
          const startRow = 2 + Math.round((startMinutes(slot.startTime) - gridStart) / STEP);
          const span = Math.max(1, Math.round(slot.durationMinutes / STEP));
          const visibleLabel = `${hhmm(slot.startTime)}${slot.capacity > 1 ? ` ·${slot.capacity}` : ""}`;
          return (
            <button
              key={slot.id}
              type="button"
              onClick={() => onSelect(slot)}
              title={`${hhmm(slot.startTime)} · ${formatDuration(slot.durationMinutes)} · cap ${slot.capacity} — cliquer pour modifier`}
              // Le `title` n'est PAS le nom accessible et ne s'affiche jamais au doigt : la
              // durée n'était lisible qu'à la souris. ⚠ Le nom accessible doit CONTENIR le
              // texte visible (WCAG 2.5.3) — sans quoi une commande vocale prononçant ce
              // qui est écrit à l'écran n'atteint plus le bouton. D'où le texte visible en
              // tête, mot pour mot, complété ensuite.
              aria-label={`${visibleLabel} · ${WEEK[di]?.label ?? ""} ${formatDuration(slot.durationMinutes)} · capacité ${slot.capacity} — modifier`}
              className={cn(
                // Full border + OPAQUE fill so a slot is always clearly bounded —
                // the old semi-transparent var(--muted) fill was identical to the
                // empty cells' hover:bg-muted, so hovering the grid made slots
                // "vanish" into the highlighted cells (reliability bug).
                "z-10 m-px flex items-start overflow-hidden rounded border border-border border-l-4 px-1 text-left text-[10px] font-medium leading-tight hover:ring-1 hover:ring-accent",
                slot.id === selectedSlotId ? "ring-2 ring-accent" : "",
              )}
              style={{ gridColumn: 2 + di, gridRow: `${startRow} / span ${span}`, borderLeftColor: color, backgroundColor: `color-mix(in oklch, ${color} 30%, var(--card))` }}
            >
              {visibleLabel}
            </button>
          );
        })}
      </div>
    </div>
  );
}
