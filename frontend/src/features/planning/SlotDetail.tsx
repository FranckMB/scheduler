import { AlertTriangle, ChevronDown, ChevronRight, Loader2, Lock, LockOpen, X } from "lucide-react";
import { VenueSelect } from "@/shared/components/ui/venue-select";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { WizardStepLink } from "@/features/wizard/WizardStepLink";

import type { Constraint, LockOrigin, MoveViolation, Slot, SlotMovePatch, Venue } from "./api";
import { applicableConstraints, isClubWide } from "./lib/applicableConstraints";
import { describeConstraint } from "./lib/describeConstraint";
import { DAYS, type GridCell, toHourMinute } from "./lib/grid";

/** Map vide partagée : une CLUB+tag ne s'affiche nulle part tant que la résolution n'est pas fournie. */
const NO_TAGS: ReadonlyMap<string, ReadonlySet<string>> = new Map();

/**
 * L'état du dernier déplacement demandé, pour l'écran (F2b). `pending` = le moteur
 * délibère (~500 ms) ; `rejected` = refusé, avec les règles violées NOMMÉES ; `blocked` =
 * une génération tourne (réessayer ensuite) ; `error` = le moteur n'a pas répondu.
 */
export type MoveFeedback =
  | { status: "idle" }
  | { status: "pending" }
  | { status: "rejected"; violations: MoveViolation[] }
  | { status: "blocked" }
  | { status: "error" };

interface SlotDetailProps {
  cell: GridCell;
  slot: Slot;
  venues: Venue[];
  categoryLabel: string;
  /** All club constraints — the applicable ones are composed here (F1). */
  constraints: Constraint[];
  /** tag NAME → équipes taguées (saison courante) : une CLUB+tag ne s'affiche que sur les
   *  équipes du tag, miroir de l'éclatement backend (cf. lib/applicableConstraints). */
  tagTeamIds?: ReadonlyMap<string, ReadonlySet<string>>;
  busy: boolean;
  /** F2b : le retour du dernier déplacement (verdict moteur). Défaut = idle. */
  moveState?: MoveFeedback;
  /** VALIDATED schedule → the slot is read-only (no move/lock). */
  readOnly?: boolean;
  onClose: () => void;
  onToggleLock: () => void;
  onMove: (patch: SlotMovePatch) => void;
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4 py-1 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span className="text-right font-medium">{value}</span>
    </div>
  );
}

/**
 * L'origine du verrou EN CLAIR (F1). ⚠ `UNKNOWN` se lit comme une IGNORANCE — le créneau EST
 * bien verrouillé, on ne sait simplement pas d'où vient le verrou : c'est cette nuance qui
 * décide si le gestionnaire ose y toucher. Jamais de code d'enum à l'écran.
 */
const LOCK_ORIGIN: Record<LockOrigin, { label: string; hint: string }> = {
  RESERVATION: { label: "Réservation gymnase", hint: "Ce créneau est réservé auprès du gymnase — ne le déplacez pas sans vérifier." },
  MANUAL: { label: "Épinglé manuellement", hint: "Vous (ou un gestionnaire) avez fixé ce créneau à la main. Vous pouvez le retirer." },
  UNKNOWN: { label: "Verrouillé — origine inconnue", hint: "Ce créneau est bien verrouillé, mais on ne sait pas pourquoi. Vérifiez avant d'y toucher." },
};

function ConstraintList({ label, items, describe }: { label: string; items: Constraint[]; describe: (c: Constraint) => string | null }) {
  return (
    <div className="mt-2 first:mt-0">
      <p className="text-[0.7rem] font-semibold uppercase tracking-wide text-muted-foreground/80">{label}</p>
      <ul className="mt-1 flex flex-col gap-1">
        {items.map((c) => {
          // Ce que la règle FAIT (dérivé de la config, vérifiable) en tête ; le nom LIBRE du
          // gestionnaire reste en second — repère utile mais faillible (périmé/copié). Quand on
          // ne sait pas décrire fidèlement (`null`), le nom redevient la seule ligne.
          const what = describe(c);

          return (
            <li key={c.id} className="flex flex-col gap-0.5 text-sm">
              <div className="flex items-start justify-between gap-2">
                <span className="min-w-0">
                  <span className="block">{what ?? c.name}</span>
                  {null !== what ? <span className="block truncate text-xs text-muted-foreground">{c.name}</span> : null}
                </span>
                <span className="mt-0.5 shrink-0 rounded-full bg-muted px-1.5 text-xs text-muted-foreground">{"HARD" === c.ruleType ? "obligatoire" : "préférence"}</span>
              </div>
              {/* P2-25 lien B — un problème DÉSIGNÉ (la règle qui contraint ce créneau) mène à son
                  lieu de correction : l'éditeur du wizard, ouvert PRÉ-REMPLI sur elle. Retour nommé
                  « ← Retour au planning ». */}
              <WizardStepLink
                step="constraints"
                params={{ edit: c.id }}
                from="planning"
                className="self-start text-xs font-medium text-accent underline underline-offset-2 hover:text-accent/80"
              >
                Corriger cette contrainte
              </WizardStepLink>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

export function SlotDetail({ cell, slot, venues, categoryLabel, constraints, tagTeamIds = NO_TAGS, busy, moveState = { status: "idle" }, readOnly = false, onClose, onToggleLock, onMove }: SlotDetailProps) {
  const [day, setDay] = useState(slot.dayOfWeek);
  const [time, setTime] = useState(toHourMinute(slot.startTime));
  const [venueId, setVenueId] = useState(slot.venueId);
  // Repliées par défaut : ouvrir un créneau ne doit pas agrandir l'aside (retour fondateur).
  // Le compte reste visible replié pour savoir s'il y a quelque chose à ouvrir.
  const [constraintsOpen, setConstraintsOpen] = useState(false);

  const dirty = day !== slot.dayOfWeek || time !== toHourMinute(slot.startTime) || venueId !== slot.venueId;

  const origin = null !== slot.lockOrigin ? LOCK_ORIGIN[slot.lockOrigin] : null;
  const applicable = applicableConstraints(slot, constraints, tagTeamIds);
  // Ce que chaque règle FAIT se dérive de sa config ; le gymnase d'une règle FACILITY se nomme
  // depuis les gymnases connus du panneau (introuvable → on retombe sur le nom, pas d'invention).
  const venueNameById = new Map(venues.map((v) => [v.id, v.name]));
  const describe = (c: Constraint): string | null => describeConstraint(c, (id) => venueNameById.get(id));
  // « Tout le club » ne concerne pas l'équipe DIRECTEMENT (fondateur) : on sépare les deux
  // groupes pour que la distinction se lise sans lire. Une CLUB+tag est côté équipe (elle
  // vise les équipes taguées, comme l'éclatement backend), pas côté club.
  const teamConstraints = applicable.filter((c) => !isClubWide(c));
  const clubConstraints = applicable.filter((c) => isClubWide(c));

  return (
    // Borné à la hauteur de l'aside (= celle de la grille) et défile en INTERNE au-delà, au lieu
    // d'étirer la page (retour fondateur : « de la même taille que la grille »). Le contrat
    // flexbox : `min-h-0` sur le conteneur qui défile (`CardContent`), sinon `overflow-y-auto`
    // ne défile jamais — un enfant flex refuse de rétrécir sous son contenu. jsdom ne peut pas
    // l'attester (aucun moteur de layout) : garde de classes ici, effet prouvé en Playwright.
    <Card className="flex min-h-0 flex-col overflow-hidden">
      <CardHeader className="shrink-0 flex-row items-center justify-between">
        <CardTitle className="flex items-center gap-2">
          {cell.teamLabel}
          {cell.locked ? <Lock className="size-4 text-muted-foreground" /> : null}
        </CardTitle>
        <button type="button" onClick={onClose} aria-label="Fermer" className="rounded p-1 text-muted-foreground hover:text-foreground">
          <X className="size-4" />
        </button>
      </CardHeader>
      <CardContent className="min-h-0 flex-1 overflow-y-auto pt-0">
        <Row label="Catégorie" value={categoryLabel} />
        <Row label="Coach" value={cell.coachLabel} />
        <Row label="Durée" value={`${slot.durationMinutes} min`} />

        {null !== origin ? (
          <div className="mt-3 border-t border-border pt-3">
            <div className="flex items-center gap-2 text-sm font-medium">
              <Lock className="size-4 text-muted-foreground" aria-hidden="true" />
              <span>{origin.label}</span>
            </div>
            <p className="mt-1 text-xs text-muted-foreground">{origin.hint}</p>
          </div>
        ) : null}

        <div className="mt-3 border-t border-border pt-3">
          <button
            type="button"
            aria-expanded={constraintsOpen}
            onClick={() => setConstraintsOpen((open) => !open)}
            className="flex w-full items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"
          >
            {constraintsOpen ? <ChevronDown className="size-3.5 shrink-0" aria-hidden="true" /> : <ChevronRight className="size-3.5 shrink-0" aria-hidden="true" />}
            Contraintes applicables ({applicable.length})
          </button>
          {constraintsOpen ? (
            applicable.length > 0 ? (
              // Plus de `max-h`/`overflow` ici : c'est désormais TOUT le panneau (`CardContent`)
              // qui est borné et défile (cf. la Card ci-dessus), donc pas de double ascenseur.
              <div className="mt-2">
                {teamConstraints.length > 0 ? <ConstraintList label="Cette équipe" items={teamConstraints} describe={describe} /> : null}
                {clubConstraints.length > 0 ? <ConstraintList label="Tout le club" items={clubConstraints} describe={describe} /> : null}
              </div>
            ) : (
              <p className="mt-1 text-xs text-muted-foreground">Aucune contrainte spécifique à ce créneau.</p>
            )
          ) : null}
        </div>

        {readOnly ? (
          <p className="mt-3 border-t border-border pt-3 text-xs text-muted-foreground">Planning validé (lecture seule). Rouvrez-le pour modifier ce créneau.</p>
        ) : (
        <div className="mt-3 flex flex-col gap-2 border-t border-border pt-3">
          <div className="grid grid-cols-3 gap-2">
            <select aria-label="Jour" value={day} onChange={(e) => setDay(Number(e.target.value))} className="h-9 rounded-md border border-input bg-background px-2 text-sm">
              {DAYS.map((d) => (
                <option key={d.n} value={d.n}>
                  {d.label}
                </option>
              ))}
            </select>
            <input aria-label="Heure" type="time" value={time} onChange={(e) => setTime(e.target.value)} className="h-9 rounded-md border border-input bg-background px-2 text-sm" />
            <VenueSelect
              aria-label="Gymnase"
              className="h-9"
              venues={venues.map((v) => ({ id: v.id, name: v.name, color: v.color }))}
              value={venueId}
              onChange={(e) => setVenueId(e.target.value)}
            />
          </div>

          <div className="flex gap-2">
            <Button size="sm" variant="outline" className="flex-1" disabled={!dirty || busy} onClick={() => onMove({ dayOfWeek: day, startTime: time, venueId })}>
              {"pending" === moveState.status ? (
                <>
                  <Loader2 className="size-4 animate-spin" aria-hidden="true" />
                  Vérification…
                </>
              ) : (
                "Déplacer"
              )}
            </Button>
            <Button size="sm" variant={cell.locked ? "default" : "outline"} className="flex-1" disabled={busy} onClick={onToggleLock}>
              {cell.locked ? <LockOpen className="size-4" /> : <Lock className="size-4" />}
              {cell.locked ? "Déverrouiller" : "Verrouiller"}
            </Button>
          </div>

          {/* Le déplacement passe sous le verdict du moteur (F2b) : ici le résultat du dernier
              essai. On ne le montre que hors « pending » (le bouton porte déjà l'attente). */}
          {"rejected" === moveState.status ? (
            <div className="rounded-md border border-destructive/40 bg-destructive/10 p-2 text-sm" role="alert">
              <div className="flex items-center gap-2 font-medium text-destructive">
                <AlertTriangle className="size-4" aria-hidden="true" />
                <span>Déplacement refusé — le créneau n’a pas bougé.</span>
              </div>
              <ul className="mt-1 flex list-disc flex-col gap-1 pl-6 text-muted-foreground">
                {moveState.violations.map((v, i) => (
                  <li key={`${v.rule}-${i}`}>{v.message}</li>
                ))}
              </ul>
            </div>
          ) : null}

          {"blocked" === moveState.status ? (
            <p className="rounded-md border border-warning/40 bg-warning/10 p-2 text-sm text-muted-foreground" role="alert">
              Une génération est en cours pour ce club — réessayez le déplacement une fois qu’elle est terminée.
            </p>
          ) : null}

          {"error" === moveState.status ? (
            <p className="rounded-md border border-warning/40 bg-warning/10 p-2 text-sm text-muted-foreground" role="alert">
              Le moteur n’a pas répondu — rien n’a été modifié, réessayez.
            </p>
          ) : null}
        </div>
        )}
      </CardContent>
    </Card>
  );
}
