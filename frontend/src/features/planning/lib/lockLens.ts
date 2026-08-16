import { CalendarClock, Lock, ShieldQuestion } from "lucide-react";

import type { LockOrigin } from "../api";

/**
 * PRÉSENTATION PURE de la lentille verrous (PR 3) : chaque origine de verrou (F1) reçoit
 * une couleur, une icône ET un libellé — jamais la couleur seule (WCAG 1.4.1). Ce module
 * est la MAISON UNIQUE de ce mapping, partagée par la grille (`WeekGrid`, l'anneau + l'icône
 * sur la cellule) et la légende (`LocksPanel`). Choisir un libellé/une icône à partir d'un
 * enum est de la présentation autorisée (cf. `matches/lib/diagnostic.ts`) — aucune règle
 * métier n'est ici re-dérivée ; `lockOrigin` n'est pas un enum policé.
 *
 * Le rouge/`destructive` reste RÉSERVÉ au conflit : la lentille ne l'emprunte jamais.
 */
export interface LockLensCategory {
  origin: LockOrigin;
  /** Libellé de la légende. */
  label: string;
  Icon: typeof Lock;
  /** Anneau posé sur la cellule de la grille quand la lentille est active. */
  ringClass: string;
  /** Pastille de la légende (fond). */
  dotClass: string;
  /** Couleur de l'icône (grille + légende). */
  textClass: string;
}

/** Ordre d'affichage de la légende, du plus « à moi » (manuel) au moins déterminé (inconnu). */
export const LOCK_LENS_ORDER: LockOrigin[] = ["MANUAL", "RESERVATION", "UNKNOWN"];

export const LOCK_LENS_META: Record<LockOrigin, LockLensCategory> = {
  MANUAL: { origin: "MANUAL", label: "Verrou manuel", Icon: Lock, ringClass: "ring-2 ring-accent", dotClass: "bg-accent", textClass: "text-accent" },
  RESERVATION: { origin: "RESERVATION", label: "Réservation de gymnase", Icon: CalendarClock, ringClass: "ring-2 ring-warning", dotClass: "bg-warning", textClass: "text-warning" },
  UNKNOWN: { origin: "UNKNOWN", label: "Origine inconnue", Icon: ShieldQuestion, ringClass: "ring-2 ring-muted-foreground", dotClass: "bg-muted-foreground", textClass: "text-muted-foreground" },
};
