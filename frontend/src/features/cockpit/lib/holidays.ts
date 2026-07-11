import type { SchoolHoliday } from "../api";

/**
 * Whether a school holiday can be "adapted" (a period overlay generated for it).
 * Summer holidays (`ete`) are off-season — a schedule spans one season, so there
 * is nothing to build. Single source of truth shared by the radar (which never
 * proposes them) and the day dialog (which shows info but no "Adapter").
 */
export const isAdaptableHoliday = (h: SchoolHoliday): boolean => "ete" !== h.holidayType;
