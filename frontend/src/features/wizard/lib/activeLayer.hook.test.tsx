import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import * as api from "../api";
import { useActiveTeams, useActiveVenues } from "../queries";

/**
 * P2-15 round 2 — la PLOMBERIE des deux hooks, pas seulement la règle.
 *
 * `activeLayer.test.ts` épingle la règle en fonctions pures ; il ne dit rien du câblage :
 * court-circuit du mode socle, fail-closed, et surtout la distinction CHARGER / ÉCHOUER
 * (le premier jet repliait `loading` sur `failed`, d'où un bandeau d'erreur à chaque
 * ouverture de période). Les tests d'écran MOCKENT ces hooks : ils ne pouvaient rien en
 * dire. On les monte donc sur un vrai QueryClient, en ne mockant que la couche API — un
 * module VOISIN, que le mock ESM intercepte bien (contrairement aux appels intra-module,
 * l'obstacle qui avait fait renoncer au round 1).
 */
vi.mock("../api", async (importOriginal) => ({
  ...(await importOriginal<typeof api>()),
  listVenues: vi.fn(),
  listTeams: vi.fn(),
  listVenuePeriodOverrides: vi.fn(),
  listTeamPeriodOverrides: vi.fn(),
}));

const VENUES = [
  { id: "v1", name: "Gymnase A", color: null, isActive: true },
  { id: "v2", name: "Gymnase B", color: null, isActive: true },
];
const TEAMS = [
  { id: "t1", name: "SM1", sessionsPerWeek: 2 },
  { id: "t2", name: "SM2", sessionsPerWeek: 2 },
];

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  vi.mocked(api.listVenues).mockResolvedValue(VENUES as never);
  vi.mocked(api.listTeams).mockResolvedValue(TEAMS as never);
  vi.mocked(api.listVenuePeriodOverrides).mockReset();
  vi.mocked(api.listTeamPeriodOverrides).mockReset();
});

describe("useActiveVenues / useActiveTeams — le câblage de la couche", () => {
  it("mode socle (planId null) : liste complète, aucune requête d'override", async () => {
    const { result } = renderHook(() => useActiveVenues(null), { wrapper });

    await waitFor(() => expect(result.current.venues).toHaveLength(2));
    expect(result.current.layerRead).toBe("ready");
    expect(api.listVenuePeriodOverrides).not.toHaveBeenCalled();
  });

  it("période : retire le gymnase DÉSACTIVÉ et rend ses identifiants", async () => {
    vi.mocked(api.listVenuePeriodOverrides).mockResolvedValue([{ id: "o1", schedulePlanId: "p1", venueId: "v2", mode: "DISABLED" }] as never);
    const { result } = renderHook(() => useActiveVenues("p1"), { wrapper });

    await waitFor(() => expect(result.current.layerRead).toBe("ready"));
    expect(result.current.venues.map((v) => v.id)).toEqual(["v1"]);
    expect(result.current.disabledIds.has("v2")).toBe(true);
  });

  // P2-37 D6 — un gymnase ENTIÈREMENT FERMÉ sur la fenêtre (donnée SERVEUR passée par
  // l'appelant, jamais redérivée) compte comme indisponible : retiré de la liste active ET
  // rangé dans `disabledIds`, au même titre qu'un DÉSACTIVÉ « override ».
  it("période : un gymnase entièrement fermé (donnée serveur) compte comme indisponible", async () => {
    vi.mocked(api.listVenuePeriodOverrides).mockResolvedValue([] as never); // aucun override
    const { result } = renderHook(() => useActiveVenues("p1", ["v2"]), { wrapper });

    await waitFor(() => expect(result.current.layerRead).toBe("ready"));
    expect(result.current.venues.map((v) => v.id)).toEqual(["v1"]);
    expect(result.current.disabledIds.has("v2")).toBe(true);
  });

  // Les deux causes se cumulent : désactivé « override » ET entièrement fermé sortent tous deux.
  it("période : DÉSACTIVÉ (override) et ENTIÈREMENT FERMÉ (serveur) sortent tous deux", async () => {
    vi.mocked(api.listVenuePeriodOverrides).mockResolvedValue([{ id: "o1", schedulePlanId: "p1", venueId: "v1", mode: "DISABLED" }] as never);
    const { result } = renderHook(() => useActiveVenues("p1", ["v2"]), { wrapper });

    await waitFor(() => expect(result.current.layerRead).toBe("ready"));
    expect(result.current.venues).toHaveLength(0);
    expect(result.current.disabledIds.has("v1")).toBe(true);
    expect(result.current.disabledIds.has("v2")).toBe(true);
  });

  // FAIL-CLOSED : masquer sur une lecture ratée ferait croire à une période plus petite
  // qu'elle n'est. On rend la liste ENTIÈRE et on le signale à l'appelant.
  it("lecture ratée : ne masque RIEN et le signale (failed)", async () => {
    vi.mocked(api.listVenuePeriodOverrides).mockRejectedValue(new Error("boom"));
    const { result } = renderHook(() => useActiveVenues("p1"), { wrapper });

    await waitFor(() => expect(result.current.layerRead).toBe("failed"));
    expect(result.current.venues).toHaveLength(2);
  });

  // LE défaut du round 1 : `loading` était rendu comme `failed`, donc l'écran criait
  // « n'a pas pu être lu » sur chaque première ouverture.
  it("premier chargement : « loading », JAMAIS « failed »", async () => {
    // Les overrides ne répondent jamais : la liste des gymnases, elle, arrive.
    vi.mocked(api.listVenuePeriodOverrides).mockReturnValue(new Promise(() => undefined) as never);
    const { result } = renderHook(() => useActiveVenues("p1"), { wrapper });

    await waitFor(() => expect(result.current.venues).toHaveLength(2));
    expect(result.current.layerRead).toBe("loading");
  });

  it("équipes : même contrat — pause retirée, pausedIds rendu, fail-closed", async () => {
    vi.mocked(api.listTeamPeriodOverrides).mockResolvedValue([{ id: "o1", schedulePlanId: "p1", teamId: "t2", isActive: false, sessionsPerWeek: null }] as never);
    const { result } = renderHook(() => useActiveTeams("p1"), { wrapper });

    await waitFor(() => expect(result.current.layerRead).toBe("ready"));
    expect(result.current.teams.map((t) => t.id)).toEqual(["t1"]);
    expect(result.current.pausedIds.has("t2")).toBe(true);
  });

  it("équipes : lecture ratée ⇒ liste entière + failed", async () => {
    vi.mocked(api.listTeamPeriodOverrides).mockRejectedValue(new Error("boom"));
    const { result } = renderHook(() => useActiveTeams("p1"), { wrapper });

    await waitFor(() => expect(result.current.layerRead).toBe("failed"));
    expect(result.current.teams).toHaveLength(2);
    expect(result.current.pausedIds.size).toBe(0);
  });
});
