import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import { HTTPError } from "ky";
import type { ReactNode } from "react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { api } from "@/shared/api/client";
import { toast } from "@/shared/stores/toastStore";

import { asWindowAlreadyPlanned, createSchedulePlan, WindowAlreadyPlannedError } from "./api";
import { useCreatePeriodPlan } from "./queries";

// On teste la PROD : le vrai `createSchedulePlan` / les vrais hooks contre le client ky mocké.
vi.mock("@/shared/api/client", () => ({ api: { post: vi.fn(), get: vi.fn(), delete: vi.fn() } }));
vi.mock("@/shared/stores/toastStore", () => ({ toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));

const mockPost = vi.mocked(api.post);

function httpError(status: number, data: unknown): HTTPError {
  const response = new Response(JSON.stringify(data), { status });
  const request = new Request("http://localhost/api/schedule_plans");
  const error = new HTTPError(response, request, {} as never);
  // ky 2.x expose le corps d'erreur parsé sur error.data (re-lire response throw).
  (error as unknown as { data: unknown }).data = data;
  return error;
}

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
}

afterEach(() => {
  mockPost.mockReset();
  vi.mocked(toast.error).mockClear();
});

describe("asWindowAlreadyPlanned — lecture du 409 structuré (P2-38)", () => {
  it("traduit un 409 window_already_planned en erreur typée PORTANT le message serveur + l'entryId", () => {
    const err = httpError(409, { code: "window_already_planned", error: "Ces dates sont déjà planifiées par « X ».", entryId: "e-conflit" });
    const parsed = asWindowAlreadyPlanned(err);
    expect(parsed).toBeInstanceOf(WindowAlreadyPlannedError);
    expect(parsed?.message).toContain("déjà planifiées");
    expect(parsed?.conflictingEntryId).toBe("e-conflit");
  });

  it("laisse passer les autres échecs (autre 409, 500) → null", () => {
    expect(asWindowAlreadyPlanned(httpError(409, { code: "overlays_exist", count: 2 }))).toBeNull();
    expect(asWindowAlreadyPlanned(httpError(500, {}))).toBeNull();
    expect(asWindowAlreadyPlanned(new Error("network"))).toBeNull();
  });
});

describe("createSchedulePlan — le geste « Adapter » sous la garde (P2-38)", () => {
  it("lève WindowAlreadyPlannedError sur un 409 window_already_planned", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(409, { code: "window_already_planned", error: "déjà planifié", entryId: "e-conflit" })) } as never);
    await expect(createSchedulePlan("entry-x")).rejects.toBeInstanceOf(WindowAlreadyPlannedError);
  });

  it("relaie tout autre échec inchangé (pas d'avalage)", async () => {
    const boom = httpError(500, {});
    mockPost.mockReturnValue({ json: () => Promise.reject(boom) } as never);
    await expect(createSchedulePlan("entry-x")).rejects.toBe(boom);
  });
});

describe("useCreatePeriodPlan — le hook POSSÈDE son feedback (P2-38)", () => {
  it("TAIT le refus de chevauchement (le dialogue l'affiche) — aucun toast générique", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(409, { code: "window_already_planned", error: "déjà planifié", entryId: "e-conflit" })) } as never);
    const { result } = renderHook(() => useCreatePeriodPlan(), { wrapper });

    result.current.mutate("entry-x");

    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(result.current.error).toBeInstanceOf(WindowAlreadyPlannedError);
    expect(toast.error).not.toHaveBeenCalled();
  });

  it("REMPLACE le filet global sur une vraie erreur transport (toast du motif)", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(500, {})) } as never);
    const { result } = renderHook(() => useCreatePeriodPlan(), { wrapper });

    result.current.mutate("entry-x");

    await waitFor(() => expect(toast.error).toHaveBeenCalledTimes(1));
  });
});
