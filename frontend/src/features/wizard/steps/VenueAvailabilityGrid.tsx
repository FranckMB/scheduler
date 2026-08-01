import { formatDuration } from "@/shared/lib/duration";
import { cn } from "@/shared/lib/utils";

import type { Venue, VenueTrainingSlot } from "../api";
import { fmtMinutes as fmt, hhmm, toMinutes as startMinutes } from "../lib/days";
import { gridTemplateColumns, gridTemplateRows, rows, START_MIN, STEP, WEEK } from "../lib/weekGrid";

interface Props {
  venue: Venue;
  slots: VenueTrainingSlot[];
  selectedSlotId: string | null;
  onAdd: (dayOfWeek: number, startTime: string) => void;
  onSelect: (slot: VenueTrainingSlot) => void;
}

export function VenueAvailabilityGrid({ venue, slots, selectedSlotId, onAdd, onSelect }: Props) {
  const color = venue.color ?? "var(--accent)";

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
          // Le DÉBUT aussi doit tomber sur une ligne qui existe. Sans borne, un créneau à
          // 07:00 (l'API ne borne pas `startTime`) donnait `startRow = -2` : en CSS une
          // ligne négative se compte depuis la FIN de la grille explicite, donc le bloc
          // s'affichait à 22:45 en portant le libellé « 07:00 » — un créneau du matin lu
          // comme un créneau du soir. À 07:30 la ligne valait 0, valeur invalide : tout le
          // `grid-row` était ignoré et le bloc placé au hasard. Borné, il se colle au bord
          // de la grille en gardant son libellé d'heure réel.
          const rawRow = 2 + Math.round((startMinutes(slot.startTime) - START_MIN) / STEP);
          const startRow = Math.min(rows.length + 1, Math.max(2, rawRow));
          // Le span est BORNÉ par la dernière ligne déclarée. Un créneau qui finit après
          // 23:00 est légitime (22:00 + 2h30) mais son bloc demanderait des lignes que
          // `gridTemplateRows` ne déclare pas : CSS en crée alors d'implicites, en `auto`,
          // hors gouttière — une bande sans heure ni bordure de jour poussait sous la
          // grille, et le conteneur défilant ajouté par P4-37 la rendait atteignable au
          // lieu de la signaler. Tronqué, le bloc dit moins que la vérité ; son `title`
          // porte la durée réelle.
          const maxSpan = Math.max(1, rows.length + 2 - startRow);
          const span = Math.min(maxSpan, Math.max(1, Math.round(slot.durationMinutes / STEP)));
          return (
            <button
              key={slot.id}
              type="button"
              onClick={() => onSelect(slot)}
              title={`${hhmm(slot.startTime)} · ${formatDuration(slot.durationMinutes)} · cap ${slot.capacity} — cliquer pour modifier`}
              // Le `title` n'est PAS le nom accessible (le texte du bouton l'emporte) et ne
              // s'affiche jamais au doigt : la durée réelle n'était lisible qu'à la souris.
              // Or un bloc tronqué — celui dont la fin déborde la grille — est précisément
              // celui qui affiche moins d'occupation qu'il n'en prend.
              aria-label={`${WEEK[di]?.label ?? ""} ${hhmm(slot.startTime)} · ${formatDuration(slot.durationMinutes)} · capacité ${slot.capacity} — modifier`}
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
              {hhmm(slot.startTime)}
              {slot.capacity > 1 ? ` ·${slot.capacity}` : ""}
            </button>
          );
        })}
      </div>
    </div>
  );
}
