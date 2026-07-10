import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useCallback, useState } from "react";

import { toast } from "@/shared/stores/toastStore";

import type { LockLevel, Schedule, SlotMovePatch } from "./api";
import { OverlaysExistError } from "./api";
import * as planningApi from "./api";

const IN_FLIGHT: Schedule["status"][] = ["PENDING", "GENERATING"];

/**
 * List of the club's schedules. While any schedule is mid-generation, poll so the
 * grid reflects PENDING → GENERATING → COMPLETED without a Mercure subscriber.
 */
export function useSchedules() {
  return useQuery({
    queryKey: ["schedules"],
    queryFn: planningApi.listSchedules,
    staleTime: 30_000,
    refetchInterval: (query) => ((query.state.data ?? []).some((s) => IN_FLIGHT.includes(s.status)) ? 2500 : false),
  });
}

export function useSlots(scheduleId: string | null) {
  return useQuery({
    queryKey: ["slots", scheduleId],
    queryFn: () => planningApi.getSlots(scheduleId as string),
    enabled: null !== scheduleId,
    staleTime: 30_000,
  });
}

export function useDiagnostics(scheduleId: string | null) {
  return useQuery({
    queryKey: ["diagnostics", scheduleId],
    queryFn: () => planningApi.getDiagnostics(scheduleId as string),
    enabled: null !== scheduleId,
    staleTime: 30_000,
  });
}

// Reference data (names + grouping). Long-lived — rarely changes within a session.
export function useTeams() {
  return useQuery({ queryKey: ["teams"], queryFn: planningApi.getTeams, staleTime: 300_000 });
}

export function useVenues() {
  return useQuery({ queryKey: ["venues"], queryFn: planningApi.getVenues, staleTime: 300_000 });
}

export function useTrainingSlots() {
  return useQuery({ queryKey: ["training-slots"], queryFn: planningApi.getTrainingSlots, staleTime: 300_000 });
}

export function useCoaches() {
  return useQuery({ queryKey: ["coaches"], queryFn: planningApi.getCoaches, staleTime: 300_000 });
}

export function useCategories() {
  return useQuery({ queryKey: ["categories"], queryFn: planningApi.getCategories, staleTime: 300_000 });
}

export function useTeamCoaches() {
  return useQuery({ queryKey: ["team_coaches"], queryFn: planningApi.getTeamCoaches, staleTime: 300_000 });
}

export function useCoachPlayers() {
  return useQuery({ queryKey: ["coach_player_memberships"], queryFn: planningApi.getCoachPlayers, staleTime: 300_000 });
}

// --- 2b: adjust + regenerate loop ---------------------------------------------

export function useLockSlot() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, lockLevel }: { id: string; lockLevel: LockLevel }) => planningApi.lockSlot(id, lockLevel),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["slots"] }),
  });
}

export function useMoveSlot() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, patch }: { id: string; patch: SlotMovePatch }) => planningApi.moveSlot(id, patch),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["slots"] }),
  });
}

export function useGenerate() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (scheduleId: string) => planningApi.generateSchedule(scheduleId),
    // The controller flips the schedule to PENDING synchronously; refetch starts the poll.
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["schedules"] }),
  });
}

export type ExportFormat = "pdf" | "png" | "xlsx";

const EXPORT_POLL_MS = 1500;
const EXPORT_TIMEOUT_MS = 60_000;
const sleep = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));

/** Trigger a browser download of a URL (same-origin) under a chosen filename. */
function download(url: string, filename: string): void {
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.rel = "noopener";
  document.body.appendChild(a);
  a.click();
  a.remove();
  // Revoke on the next macrotask: a synchronous revoke can cancel the download
  // the click just started in some browsers.
  if (url.startsWith("blob:")) {
    setTimeout(() => URL.revokeObjectURL(url), 30_000);
  }
}

/**
 * Export a schedule to PDF/PNG (async worker → poll status → download the file)
 * or XLSX (synchronous blob → download). `busy` is the in-flight format, or null.
 */
export function useScheduleExport(scheduleId: string | null) {
  const [busy, setBusy] = useState<ExportFormat | null>(null);

  const run = useCallback(
    async (format: ExportFormat, venueId: planningApi.ExportVenueScope): Promise<void> => {
      if (null === scheduleId || null !== busy) {
        return;
      }
      setBusy(format);
      try {
        if ("xlsx" === format) {
          const blob = await planningApi.exportScheduleXlsx(scheduleId, venueId);
          download(URL.createObjectURL(blob), "planning.xlsx");
          return;
        }
        await planningApi.exportSchedulePdf(scheduleId, venueId);
        // The worker writes the file path with a scope suffix (-all / -<venueId8>);
        // the schedule row carries a single, shared export URL, so only download
        // once it matches THIS request's scope — guards against another in-flight
        // export (other tab/scope) whose 'completed' + URL we'd otherwise grab.
        const scopeToken = `-${null === venueId ? "all" : venueId.slice(0, 8)}.${format}`;
        const deadline = Date.now() + EXPORT_TIMEOUT_MS;
        for (;;) {
          await sleep(EXPORT_POLL_MS);
          const schedule = await planningApi.getSchedule(scheduleId);
          if ("failed" === schedule.pdfExportStatus || Date.now() > deadline) {
            throw new Error("export failed");
          }
          const url = "pdf" === format ? schedule.pdfExportUrl : schedule.pngExportUrl;
          if ("completed" === schedule.pdfExportStatus && null != url && url.endsWith(scopeToken)) {
            download(url, `planning.${format}`);
            return;
          }
        }
      } catch {
        toast.error("Export impossible — réessayez.");
      } finally {
        setBusy(null);
      }
    },
    [scheduleId, busy],
  );

  return { run, busy };
}

/** Lock a COMPLETED schedule → VALIDATED (read-only). */
export function useValidateSchedule() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, confirmDeleteOverlays }: { id: string; confirmDeleteOverlays?: boolean }) => planningApi.validateSchedule(id, { confirmDeleteOverlays }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["schedules"] });
      // Validating the baseline stamps Season.socleValidatedAt (surfaced on /me),
      // which unlocks the cockpit — refresh it so the home screen opens at once.
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    },
  });
}

/** Reopen a VALIDATED schedule → COMPLETED (editable again). Accepts the overlay-delete confirm flag. */
export function useReopenSchedule() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, confirmDeleteOverlays }: { id: string; confirmDeleteOverlays?: boolean }) =>
      planningApi.reopenSchedule(id, { confirmDeleteOverlays }),
    // Hook-level = unmount-safe. OverlaysExistError is UI state (escalation
    // dialog, handled by the caller's mutate-level onError); everything else
    // toasts here so a failure is never silent.
    onError: (error) => {
      if (!(error instanceof OverlaysExistError)) {
        toast.error("Réouverture impossible");
      }
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["schedules"] });
      void queryClient.invalidateQueries({ queryKey: ["calendar-entries"] });
    },
  });
}

/** Designate a schedule as the season's main plan (baseline lives on /me). */
export function useSetBaseline() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (scheduleId: string) => planningApi.setBaseline(scheduleId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["me"] });
      void queryClient.invalidateQueries({ queryKey: ["schedules"] });
    },
  });
}

export function useRenameSchedule() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, name, status }: { id: string; name: string; status: Schedule["status"] }) => planningApi.renameSchedule(id, name, status),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["schedules"] }),
  });
}

/** planning-versions: delete a work version (guards live server-side). */
export function useDeleteSchedule() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => planningApi.deleteSchedule(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["schedules"] }),
  });
}

/** planning-versions D3: regenerate under a version's conditions → a new version. */
export function useRegenerateFromVersion() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => planningApi.regenerateFromVersion(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["schedules"] });
      // The restore replaced the WHOLE structure — refresh every cached family
      // (wizard + the planning reference lists, all staleTime 300 s).
      void queryClient.invalidateQueries({ queryKey: ["wizard"] });
      for (const key of ["teams", "venues", "coaches", "categories", "priority_tiers"]) {
        void queryClient.invalidateQueries({ queryKey: [key] });
      }
    },
    onError: () => toast.error("La régénération aux conditions de cette version a échoué."),
  });
}

export function useRegenerate() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => planningApi.regenerate(id),
    // A NEW version row appears — refresh the version list (the current structure
    // is unchanged, so no need to refetch the reference families).
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["schedules"] }),
    onError: () => toast.error("La régénération a échoué."),
  });
}
