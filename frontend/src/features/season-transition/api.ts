import type { CalendarEntry } from "@/features/cockpit/api";
import { api } from "@/shared/api/client";
import { collectionAll } from "@/shared/api/collection";

/**
 * Cross-season calls of the re-dating step (transition P2-PR1). Both target an
 * EXPLICIT season via the X-Season-Id header (the request-level header wins
 * over the store, see client.ts) — the server validates it: foreign/unknown
 * season → 403, archived season write → 409. No new backend surface: this is
 * the standard calendar_entries API, scoped season by season.
 */

/** The re-datable club events of a season (kind=event, not dismissed). */
export const getSeasonEvents = async (seasonId: string): Promise<CalendarEntry[]> => {
  const entries = await collectionAll<CalendarEntry>("calendar_entries", { kind: "event" }, { "X-Season-Id": seasonId });

  return entries.filter((entry) => "ignored" !== entry.status);
};

export interface RedateEventPayload {
  title: string;
  startDate: string;
  endDate: string;
  isDisruptive: boolean;
}

/** Recreate one kept event in the target (draft) season at its new dates. */
export const redateEvent = (targetSeasonId: string, payload: RedateEventPayload): Promise<CalendarEntry> =>
  api
    .post("calendar_entries", {
      json: { kind: "event", status: "active", ...payload },
      headers: { "X-Season-Id": targetSeasonId },
    })
    .json<CalendarEntry>();
