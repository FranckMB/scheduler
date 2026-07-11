import type { CalendarEntry, SchoolHoliday } from "../api";

/**
 * Single home of the calendar's visual markers, shared by the month grid AND the
 * day dialog so an entry looks the same in both (user request: same emoji in the
 * modal as on the calendar). Emojis are decorative — always pair them with the
 * entry title / an aria-label at the call site.
 */

/** Emoji marker for a calendar entry (event / period). */
export const entryIcon = (e: CalendarEntry): string => {
  if (e.kind === "period") {
    return e.periodType === "cutoff" ? "🛑" : "⛔";
  }
  return e.isDisruptive ? "🚫" : "🎉";
};

/** Emoji per school-holiday period, so the marker fits the season (not a beach for winter). */
const HOLIDAY_ICON: Record<string, string> = {
  ete: "🏖️", // été → plage
  toussaint: "🎃", // automne → citrouille
  noel: "🎄", // Noël → sapin
  hiver: "⛷️", // hiver → ski
  printemps: "🐰", // printemps / Pâques → lapin
};
export const holidayIcon = (h: SchoolHoliday): string => HOLIDAY_ICON[h.holidayType] ?? "🏖️";

/**
 * Accessible/fallback name for an entry marker. `title` may be empty (imported /
 * auto-named entries), so fall back to the marker's meaning — never an empty
 * label (a silent marker for a screen reader).
 */
export const entryLabel = (e: CalendarEntry): string => {
  if (e.title.trim() !== "") {
    return e.title;
  }
  if (e.kind === "period") {
    return e.periodType === "cutoff" ? "Coupure" : "Période fermée";
  }
  return e.isDisruptive ? "Événement perturbant" : "Événement";
};
