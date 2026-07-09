import { DAYS } from "./days";

/**
 * Shared weekly-grid geometry for the wizard slot grids (Gymnases availability +
 * Réserver). Single source of truth so the two grids can never drift apart.
 */
export const START_MIN = 8 * 60; // 08:00
export const END_MIN = 22 * 60; // 22:00
export const STEP = 15; // 15-minute graduation (slots start/last on quarter-hours)
export const ROW_H = 11; // px per 15 min (~44px/hour)
export const WEEK = DAYS.filter((d) => d.n <= 6); // Lun–Sam

export const rows = Array.from({ length: (END_MIN - START_MIN) / STEP }, (_, i) => START_MIN + i * STEP);

export const gridTemplateColumns = `3rem repeat(${WEEK.length}, minmax(3rem, 1fr))`;
export const gridTemplateRows = `1.5rem repeat(${rows.length}, ${ROW_H}px)`;
