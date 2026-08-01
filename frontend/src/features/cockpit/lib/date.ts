/** Cockpit calendar date helpers — pure, no date library. All dates are ISO Y-m-d. */

// « Aujourd'hui » vit désormais dans `shared/lib/clock` (une seule source pour tout le
// front, pilotable en dev). Ré-exporté ici pour que les 9 fichiers qui l'importent depuis
// ce module restent inchangés — P4-16 migrera les appelants quand elle traitera le serveur.
export { toISODate, todayISO } from "@/shared/lib/clock";
import { toISODate } from "@/shared/lib/clock";

const MONTH_LABELS = ["Janvier", "Février", "Mars", "Avril", "Mai", "Juin", "Juillet", "Août", "Septembre", "Octobre", "Novembre", "Décembre"];

export const monthLabel = (month: number): string => MONTH_LABELS[month] ?? "";

/** First and last ISO date covering the calendar grid for a given month (Monday-first, 6 weeks). */
export function monthWindow(year: number, month: number): { from: string; to: string } {
  const grid = buildMonthGrid(year, month);
  return { from: grid[0].iso, to: grid[grid.length - 1].iso };
}

export interface GridDay {
  iso: string;
  day: number;
  inMonth: boolean;
}

/**
 * A 6-row Monday-first grid of the month. Leading/trailing days spill from the
 * adjacent months so every row has 7 cells.
 */
/** getDay(): 0=Sun..6=Sat → décalage Monday-first (Mon=0 … Sun=6). Source unique
 *  partagée par la grille du mois et le découpage en semaines (mondayOf). */
const mondayOffset = (date: Date): number => (date.getDay() + 6) % 7;

export function buildMonthGrid(year: number, month: number): GridDay[] {
  const first = new Date(year, month, 1);
  const offset = mondayOffset(first);
  const start = new Date(year, month, 1 - offset);

  const days: GridDay[] = [];
  for (let i = 0; i < 42; i += 1) {
    const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
    days.push({ iso: toISODate(d), day: d.getDate(), inMonth: d.getMonth() === month });
  }
  return days;
}

/** Whether ISO date `d` falls within the inclusive [start, end] range (string compare is safe for Y-m-d). */
export function isWithin(d: string, start: string, end: string): boolean {
  return d >= start && d <= end;
}

/** ISO date `n` days after `iso`. */
export function addDays(iso: string, n: number): string {
  const [y, m, d] = iso.split("-").map(Number);
  return toISODate(new Date(y, m - 1, d + n));
}

/** Numeric French date dd-mm-yyyy (FR reading order, dashes), e.g. "2026-10-17" → "17-10-2026". */
export function frDateNumeric(iso: string): string {
  const [y, m, d] = iso.split("-");
  return `${d}-${m}-${y}`;
}

/** Short French date for compact UI copy, e.g. "2026-12-19" → "19 déc. 2026". */
export function frDateShort(iso: string): string {
  const [y, m, d] = iso.split("-").map(Number);
  return new Date(y, m - 1, d).toLocaleDateString("fr-FR", { day: "numeric", month: "short", year: "numeric" });
}

/** Whole days from `from` to `to` (ISO), floored, negative if `to` is before `from`. */
export function daysUntil(from: string, to: string): number {
  const a = Date.parse(`${from}T00:00:00`);
  const b = Date.parse(`${to}T00:00:00`);
  return Math.round((b - a) / 86_400_000);
}

/**
 * Intersect an ISO [start, end] range with the season window; null when disjoint.
 * Une période de calendrier vit DANS sa saison (un planning couvre une saison) :
 * les vacances d'été chevauchent la frontière — on n'écrit jamais leurs jours
 * hors-saison dans le calendrier de la saison courante (revue #260 round 1).
 */
export function clampRangeToSeason(
  start: string,
  end: string,
  season: { startDate: string; endDate: string },
): { startDate: string; endDate: string } | null {
  const s = start > season.startDate ? start : season.startDate;
  const e = end < season.endDate ? end : season.endDate;
  return s <= e ? { startDate: s, endDate: e } : null;
}

/** Le lundi de la semaine ISO contenant `iso` (même décalage que la grille du mois). */
export function mondayOf(iso: string): string {
  const [y, m, d] = iso.split("-").map(Number);
  return toISODate(new Date(y, m - 1, d - mondayOffset(new Date(y, m - 1, d))));
}

export interface WeekWindow {
  /** Fenêtre du plan de semaine : lun→dim, clampée à la saison. */
  startDate: string;
  endDate: string;
  /** Le lundi théorique (clé d'affichage stable, même si la saison rogne la fenêtre). */
  monday: string;
}

/**
 * Les semaines pleines (lun→dim) couvrant [start, end], chacune clampée à la
 * saison (P2-5 E1 : « la semaine est l'unité hors socle »). Une semaine
 * entièrement hors saison est omise.
 */
export function weeksCovering(start: string, end: string, season: { startDate: string; endDate: string }): WeekWindow[] {
  const weeks: WeekWindow[] = [];
  for (let monday = mondayOf(start); monday <= end; monday = addDays(monday, 7)) {
    const clamped = clampRangeToSeason(monday, addDays(monday, 6), season);
    if (null !== clamped) {
      weeks.push({ startDate: clamped.startDate, endDate: clamped.endDate, monday });
    }
  }
  return weeks;
}

/** La date `iso` tombe-t-elle Ven/Sam/Dim ? (mondayOffset : Lun=0 … Ven=4, Sam=5, Dim=6). */
function startsLateInWeek(iso: string): boolean {
  const [y, m, d] = iso.split("-").map(Number);
  return mondayOffset(new Date(y, m - 1, d)) >= 4;
}

/**
 * Les semaines à AJUSTER d'une période. Cas particulier VACANCES (holiday) démarrant
 * Ven/Sam/Dim (retour fondateur 2026-07-19) : la semaine partielle de début n'a pas
 * d'impact réel (les vacances tombent le soir venu → l'impact est sur les semaines
 * SUIVANTES) — on l'écarte, l'ajustement commence au lundi suivant. Ex. Toussaint
 * ven 16 oct → 1er nov : on propose les semaines du 19–25 et 26–01, pas le 12–18.
 * Règle réservée aux vacances : fermetures/coupures gardent weeksCovering. La garde
 * `length > 1` évite de renvoyer vide (un week-end de vacances isolé garde sa semaine).
 */
/**
 * P3-13 — UNE SEMAINE EST ACTIONNABLE TANT QU'IL LUI RESTE DES JOURS DEVANT.
 *
 * Besoin fondateur 2026-08-01 : le radar comptait « 0/7 semaines couvertes » alors que 3
 * étaient DERRIÈRE, et la campagne coachs sollicitait pour du passé. « On gère l'avenir,
 * pas le présent. »
 *
 * ⚠ Le premier jet lisait ça comme « la semaine n'a pas COMMENCÉ » (`monday > today`), et
 * la revue #344 a montré que c'est faux et dangereux — « commencé » n'est pas « fini » :
 *  - une fermeture du MERCREDI 11 devenait implanifiable dès le lundi 9, parce que la
 *    puce « + créer » de sa semaine disparaissait alors que la fermeture était encore
 *    entièrement devant (et le DayDialog ne reproduit ces puces que pour les vacances) ;
 *  - une vacance démarrant un samedi ne pouvait plus faire l'objet d'une collecte le lundi
 *    suivant, pour des séances pourtant toutes à venir ;
 *  - une semaine rognée par le début de saison (saison démarrant un mardi) était déclarée
 *    « commencée » le lundi d'avant, alors que la saison n'existait pas encore.
 *
 * D'où le critère : `endDate >= today` — la semaine reste tant qu'un de ses jours n'est pas
 * passé. C'est EXACTEMENT le test que le radar applique déjà au niveau période
 * (`e.endDate >= today`) : une seule notion de « c'est derrière », à deux échelles.
 *
 * On lit donc `endDate` et non `monday` : le lundi dit QUELLE semaine c'est (clé stable),
 * la fin dit s'il reste quelque chose à y faire. Ce sont deux questions différentes.
 *
 * Fonctions PURES, hors React : les tests d'écran mockent les hooks et ne garderaient que
 * le câblage (leçon P2-15 / CLAUDE.md §7.2).
 */
export function isActionableWeek(week: WeekWindow, today: string): boolean {
  return week.endDate >= today;
}

/** Les semaines de `weeks` qu'il reste quelque chose à traiter. @see isActionableWeek */
export function actionableWeeks(weeks: WeekWindow[], today: string): WeekWindow[] {
  return weeks.filter((w) => isActionableWeek(w, today));
}

/**
 * LES SEMAINES QU'UNE PÉRIODE OFFRE ENCORE — le seul point d'entrée pour OFFRIR une
 * semaine, partout (radar, modale du jour, picker).
 *
 * `periodAdjustWeeks` répond à la GÉOMÉTRIE (« quelles semaines cette période couvre-t-elle,
 * la partielle du vendredi écartée »), pas au TEMPS. Les avoir gardées séparées a coûté
 * (revue #344 round 2) : le picker proposait — et cochait — des semaines révolues, dont la
 * création produisait un plan de semaine que le radar filtrait ensuite partout. Un
 * artefact sans carte, sans puce et sans retour possible.
 *
 * Une règle vaut à TOUS ses sites, sinon les écrans se contredisent (CLAUDE.md §7.2 pt 1).
 */
export function periodWeeksToAdjust(
  start: string,
  end: string,
  season: { startDate: string; endDate: string },
  periodType: string | null,
  today: string,
): WeekWindow[] {
  return actionableWeeks(periodAdjustWeeks(start, end, season, periodType), today);
}

export function periodAdjustWeeks(start: string, end: string, season: { startDate: string; endDate: string }, periodType: string | null): WeekWindow[] {
  const weeks = weeksCovering(start, end, season);
  // Garde `weeks[0].startDate === monday` (revue C F3) : on n'écarte QUE si la 1ʳᵉ
  // semaine est PLEINE (lun→dim). Si la saison a rogné son début (vacance à cheval
  // clampée à un début de saison qui tombe Ven/Sam/Dim), ce n'est pas le cas
  // « la vacance commence en fin de semaine » du fondateur : on garde cette semaine
  // en-saison réelle.
  const dropFirst = "holiday" === periodType && weeks.length > 1 && startsLateInWeek(start) && weeks[0].startDate === weeks[0].monday;
  return dropFirst ? weeks.slice(1) : weeks;
}
