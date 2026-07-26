import { useMutation, useQueries, useQuery, useQueryClient } from "@tanstack/react-query";
import { HTTPError } from "ky";

import { createConstraint } from "@/features/wizard/api";
import { errorMessage } from "@/shared/lib/errorMessage";
import { toast } from "@/shared/stores/toastStore";

import * as cockpitApi from "./api";
import type { CalendarEntry, CreateClosurePayload, CreateCutoffPayload, CreateEventPayload } from "./api";
import { frDateShort } from "./lib/date";

export function useCalendarEntries(from: string, to: string, enabled = true) {
  return useQuery({
    queryKey: ["calendar-entries", from, to],
    queryFn: () => cockpitApi.getCalendarEntries(from, to),
    enabled,
    staleTime: 30_000,
  });
}

export function useCalendarEntry(id: string | null) {
  return useQuery({
    // Under the "calendar-entries" prefix so the shared invalidation (creation,
    // overlay generation, reopen) also refreshes the detail — a singular key
    // kept a stale plan pointer (chosenScheduleId) for 30s after validating.
    queryKey: ["calendar-entries", "detail", id],
    queryFn: () => cockpitApi.getCalendarEntry(id as string),
    enabled: null !== id,
    staleTime: 30_000,
    // A 404 means the entry was deleted — the wizard exits period mode cleanly
    // (WizardPage effect) instead of the global net toasting a raw error.
    meta: { silent404: true },
  });
}

/**
 * Le plan d'une période (ADR-0002 lot C). Il naît avec le geste « ajuster cette
 * période », donc il est là dès que l'entrée existe — inutile d'attendre une
 * génération. Porte les réglages de la période (inv. 5), dont le garde de seed
 * `teamSelectionInitialized`.
 *
 * Le flag `teamSelectionInitialized` bascule côté SERVEUR au 1er override, sans
 * mutation directe sur le plan : aucune invalidation ne le rafraîchit (les mutations
 * d'override n'invalident que ["wizard", "team_period_overrides", …]). Ce qui protège
 * le seed d'un double déclenchement, c'est le garde `periodSeedWasClaimed` — pas cette
 * clé. Ne pas retirer ce garde en croyant qu'un refetch prend le relais.
 */
export function useSchedulePlanForEntry(calendarEntryId: string | null) {
  return useQuery({
    queryKey: ["calendar-entries", "plan", calendarEntryId],
    queryFn: () => cockpitApi.getSchedulePlanForEntry(calendarEntryId as string),
    enabled: null !== calendarEntryId,
    staleTime: 30_000,
  });
}

/**
 * Tous les plans de la saison — le radar y lit, PAR PÉRIODE, la « version active »
 * (chosenScheduleId, ADR-0002 lot D-b). Sous le préfixe "calendar-entries" pour que
 * l'invalidation partagée (génération d'overlay, validation, reopen) le rafraîchisse.
 */
export function useSchedulePlans() {
  return useQuery({
    queryKey: ["calendar-entries", "plans"],
    queryFn: () => cockpitApi.getAllSchedulePlans(),
    staleTime: 30_000,
  });
}

/**
 * L'ANCRE des réglages d'une période, et son état — ADR-0002 inv. 5 (lots C2-C3).
 *
 * À utiliser PARTOUT plutôt que `useSchedulePlanForEntry(x).data?.id ?? null`. Cet idiome
 * nu a produit deux bugs en deux rounds de review, et toujours le même : il écrase
 * « le plan n'est pas encore résolu » et « mode socle » dans le même `null`.
 *
 * Or `null` est une ancre LÉGITIME — elle veut dire « ligne de base », structure partagée
 * (inv. 6) — que le serveur ne peut pas refuser. Écrire pendant la fenêtre de chargement
 * pose donc le réglage SUR LE SOCLE DU CLUB : le gymnase prêté pour une semaine de
 * vacances devient un créneau permanent, nourrit toutes les générations de la saison, et
 * se transmet à N+1. Aucune erreur, aucun signal.
 *
 * `ready` répond « sait-on où écrire ? » :
 *  - hors mode période (`calendarEntryId` null), `planId` null EST la bonne réponse → prêt ;
 *  - en mode période, il faut le plan → pas prêt tant qu'il n'est pas là.
 *
 * **Ne jamais écrire un réglage quand `ready` est faux.** Lire est sans risque (la requête
 * est simplement désactivée), mais l'appelant doit alors afficher un état de CHARGEMENT —
 * une liste vide affirmerait « aucun réglage », ce qui pousse le gestionnaire à les
 * re-saisir… et donc à déclencher l'écriture corrompue.
 */
export function usePeriodAnchor(calendarEntryId: string | null): { planId: string | null; ready: boolean; isLoading: boolean } {
  const { data, isLoading } = useSchedulePlanForEntry(calendarEntryId);
  const planId = data?.id ?? null;

  return { planId, ready: null === calendarEntryId || null !== planId, isLoading };
}

/**
 * School holidays. Without a window → the season default (radar, season-wide).
 * With a [from, to] → that window (the calendar's visible month, so summer and
 * any month outside the season are shown when browsed).
 */
export function useSchoolHolidays(from?: string, to?: string) {
  return useQuery({
    queryKey: ["school-holidays", from ?? null, to ?? null],
    queryFn: () => cockpitApi.getSchoolHolidays(from, to),
    staleTime: 3_600_000,
  });
}

export function usePublicHolidays(from: string, to: string) {
  return useQuery({
    queryKey: ["public-holidays", from, to],
    queryFn: () => cockpitApi.getPublicHolidays(from, to),
    staleTime: 3_600_000,
  });
}

/**
 * Les conflits de PLUSIEURS périodes d'un coup. Le radar est une liste « à traiter » :
 * il ne peut décider de masquer une fermeture sans impact qu'en connaissant l'impact,
 * or seul le serveur le sait. Même queryKey que useEntryConflicts → le cache dédoublonne,
 * la carte enfant ne refait aucune requête.
 */
export function useEntryConflictsList(entryIds: string[]) {
  return useQueries({
    queries: entryIds.map((entryId) => ({
      queryKey: ["entry-conflicts", entryId],
      queryFn: () => cockpitApi.getEntryConflicts(entryId),
      staleTime: 30_000,
    })),
  });
}

export function useEntryConflicts(entryId: string | null) {
  return useQuery({
    queryKey: ["entry-conflicts", entryId],
    queryFn: () => cockpitApi.getEntryConflicts(entryId as string),
    enabled: null !== entryId,
    staleTime: 30_000,
  });
}

function invalidateEntries(queryClient: ReturnType<typeof useQueryClient>): void {
  void queryClient.invalidateQueries({ queryKey: ["calendar-entries"] });
}

/**
 * LE GESTE « Adapter » (ADR-0002 amendé 2026-07-24) : crée le plan de la période
 * AVANT d'ouvrir le wizard — une période n'a plus de plan à sa matérialisation,
 * et usePeriodAnchor attendrait à l'infini sans lui. Idempotent côté serveur.
 * L'invalidation du préfixe ["calendar-entries"] couvre "plan"/"plans".
 */
export function useCreatePeriodPlan() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (calendarEntryId: string) => cockpitApi.createSchedulePlan(calendarEntryId),
    onSuccess: () => invalidateEntries(queryClient),
  });
}

export function useCreateEvent() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateEventPayload) =>
      cockpitApi.createCalendarEntry({
        kind: "event",
        title: payload.title,
        startDate: payload.startDate,
        endDate: payload.endDate,
        isDisruptive: payload.isDisruptive,
      }),
    onSuccess: () => invalidateEntries(queryClient),
  });
}

/**
 * A venue closure is a period entry PLUS a dated FACILITY constraint that carries
 * the closed venue. Two calls: if the constraint fails, roll back the entry. If
 * the rollback ALSO fails, surface a distinct error so the orphan period (a ⛔
 * marker with no closed-venue constraint) is not hidden behind a generic failure.
 */
export function useCreateVenueClosure() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (payload: CreateClosurePayload) => {
      const entry = await cockpitApi.createCalendarEntry({
        kind: "period",
        periodType: "closure",
        title: payload.title,
        startDate: payload.startDate,
        endDate: payload.endDate,
      });
      try {
        await createConstraint({
          name: payload.title,
          scope: "FACILITY",
          scopeTargetId: payload.venueId,
          family: "FACILITY",
          ruleType: "HARD",
          config: { type: "venue_closed", startDate: payload.startDate, endDate: payload.endDate },
          calendarEntryId: entry.id,
        });
      } catch (error) {
        try {
          await cockpitApi.deleteCalendarEntry(entry.id);
        } catch {
          throw new Error("La salle n'a pas pu être bloquée et l'annulation a échoué — supprime la période à la main.");
        }
        throw error;
      }
      return entry;
    },
    // Hook-level (unmount-safe): surfaces the tailored rollback message even if
    // the DayDialog was closed while the two-call sequence was in flight.
    onError: (error) => {
      if (error instanceof Error && !("response" in error)) {
        toast.error(error.message);
        return;
      }
      void errorMessage(error).then((message) => toast.error(message));
    },
    onSuccess: () => invalidateEntries(queryClient),
  });
}

/** "Adapter" on a school holiday first materialises it as a period entry (holiday), then period mode adapts it. */
export function useCreateHolidayPeriod() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (holiday: { schoolHolidayId: string; label: string; startDate: string; endDate: string }) =>
      cockpitApi.createCalendarEntry({
        kind: "period",
        periodType: "holiday",
        title: holiday.label,
        startDate: holiday.startDate,
        endDate: holiday.endDate,
        schoolHolidayId: holiday.schoolHolidayId,
      }),
    onSuccess: () => invalidateEntries(queryClient),
  });
}

/** Le 422 « cette semaine existe déjà » (chevauchement) — le SEUL sauté par
 *  useCreateWeekChildren : l'état visé existe. Tout autre 422 (titre trop long, mère
 *  générée en bloc, type) est une vraie erreur (revue #262 round 2). Match sur le
 *  noyau stable « déjà découpée » (moins fragile qu'un reword ; couplage au message
 *  serveur assumé tant qu'il n'y a pas de code machine — revue #262 round 3). */
async function isAlreadySplit422(error: unknown): Promise<boolean> {
  if (!(error instanceof HTTPError) || 422 !== error.response.status) {
    return false;
  }
  try {
    const body: unknown = await error.response.clone().json();
    const detail = "object" === typeof body && null !== body && "detail" in body && "string" === typeof body.detail ? body.detail : "";
    return detail.includes("déjà découpée");
  } catch {
    return false;
  }
}

export interface WeekChildrenResult {
  created: CalendarEntry[];
  /** Semaines en ÉCHEC RÉEL (hors « existe déjà ») — l'appelant doit le dire. */
  failedCount: number;
}

/**
 * P2-5 E1 — découpe une période mère en SEMAINES : une entrée ENFANT par semaine
 * cochée (parentEntryId), type hérité, titre E6 (« {mère} — semaine du {lundi} »).
 * Chaque enfant naît avec son plan (rail 1 entrée = 1 plan).
 *
 * Reprenable (revue #262) : seul le 422 « chevauche une semaine déjà découpée »
 * est sauté (l'état visé existe — un retry ne meurt plus dessus) ; toute autre
 * erreur est comptée (failedCount) et relevée si RIEN n'a été créé. Invalidation
 * en onSettled : même un échec partiel rafraîchit le cache (les enfants créés
 * apparaissent, les chips « à créer » listent les manquantes). Titre borné à 180
 * (colonne title) — un titre de mère long ne fait plus 422 chaque semaine.
 */
export function useCreateWeekChildren() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (payload: { mother: CalendarEntry; weeks: { startDate: string; endDate: string; monday: string }[] }): Promise<WeekChildrenResult> => {
      const created: CalendarEntry[] = [];
      let failedCount = 0;
      let firstHardError: unknown = null;
      for (const week of payload.weeks) {
        try {
          created.push(
            await cockpitApi.createCalendarEntry({
              kind: "period",
              periodType: payload.mother.periodType,
              title: `${payload.mother.title} — semaine du ${frDateShort(week.monday)}`.slice(0, 180),
              startDate: week.startDate,
              endDate: week.endDate,
              parentEntryId: payload.mother.id,
            }),
          );
        } catch (error) {
          if (await isAlreadySplit422(error)) {
            continue;
          }
          failedCount += 1;
          firstHardError = firstHardError ?? error;
        }
      }
      if (null !== firstHardError && 0 === created.length) {
        throw firstHardError;
      }
      return { created, failedCount };
    },
    onSettled: () => invalidateEntries(queryClient),
  });
}

/** A cutoff means "no training on the window" — a bare period entry, no dated constraint, never an overlay. */
export function useCreateCutoff() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateCutoffPayload) =>
      cockpitApi.createCalendarEntry({
        kind: "period",
        periodType: "cutoff",
        title: payload.title,
        startDate: payload.startDate,
        endDate: payload.endDate,
      }),
    onSuccess: () => invalidateEntries(queryClient),
  });
}

export function useDeleteEntry() {
  const queryClient = useQueryClient();
  return useMutation({
    // The backend cascades the entry's dated constraints AND its overlay
    // schedule on delete → schedules and conflicts must refresh too, or the
    // baseline banner keeps counting a ghost overlay ("Voir le plan" → 404).
    mutationFn: (id: string) => cockpitApi.deleteCalendarEntry(id),
    onSuccess: () => {
      invalidateEntries(queryClient);
      void queryClient.invalidateQueries({ queryKey: ["schedules"] });
      void queryClient.invalidateQueries({ queryKey: ["entry-conflicts"] });
    },
  });
}
