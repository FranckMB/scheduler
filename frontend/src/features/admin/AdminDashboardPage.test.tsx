import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { axe } from "vitest-axe";

import { renderWithProviders } from "@/test/utils";

import type { AdminClubsResponse, AdminHealthResponse, AdminJobsResponse, AdminOverviewResponse } from "./api";
import { getAdminClubs, getAdminHealth, getAdminJobs, getAdminOverview, runAdminJob } from "./api";
import { AdminDashboardPage } from "./AdminDashboardPage";
import { useAdminStore } from "./store";

vi.mock("./api", async (importOriginal) => {
  const original = await importOriginal<typeof import("./api")>();
  return {
    ...original,
    getAdminOverview: vi.fn(),
    getAdminHealth: vi.fn(),
    getAdminJobs: vi.fn(),
    getAdminClubs: vi.fn(),
    runAdminJob: vi.fn(),
  };
});

const overview: AdminOverviewResponse = {
  clubs: { total: 18, active7d: 7, active30d: 12, new7d: 2, unsubscribed: 1 },
  solver: {
    windowDays: 30,
    generations: 42,
    completed: 36,
    failed: 2,
    infeasible: 4,
    infeasibleRate: 4 / 42,
    p50WallTimeMs: 850,
    p95WallTimeMs: 2400,
    daily: [
      { date: "2026-07-15", generations: 14, infeasible: 1, p50WallTimeMs: 700, p95WallTimeMs: 1900 },
      { date: "2026-07-16", generations: 28, infeasible: 3, p50WallTimeMs: 900, p95WallTimeMs: 2600 },
    ],
  },
  usage: {
    plansByType: [
      { type: "SEASON", total: 18, validated: 11 },
      { type: "CLOSURE", total: 6, validated: 4 },
      { type: "HOLIDAY", total: 9, validated: 5 },
    ],
    timeToFirstValidation: {
      // Minutes (SA2-stats round 1) : 36 h · 320 h→13 j · 25 min (le cas « clôture rapide » que l'arrondi heures effaçait).
      season: { count: 11, p50Minutes: 36 * 60, p95Minutes: 320 * 60 },
      period: { count: 9, p50Minutes: 25, p95Minutes: 30 * 60 },
    },
    solverByPlanType: [
      { planType: "SEASON", generations: 30, p50WallTimeMs: 900, p95WallTimeMs: 2600 },
      { planType: "CLOSURE", generations: 12, p50WallTimeMs: 400, p95WallTimeMs: 1100 },
    ],
    clubSizes: [
      { bucket: "1-5", clubs: 8, medianVenues: 1 },
      { bucket: "11-20", clubs: 5, medianVenues: 3 },
    ],
  },
};

const health: AdminHealthResponse = {
  status: "healthy",
  checkedAt: "2026-07-16T10:30:00+00:00",
  services: {
    database: { status: "up", latencyMs: 4 },
    redis: { status: "up", latencyMs: 2 },
    engine: { status: "up", latencyMs: 18 },
    mercure: { status: "up", latencyMs: 9 },
    worker: { status: "up", lastHeartbeatAt: "2026-07-16T10:29:55+00:00", ageSeconds: 5 },
  },
  messenger: { status: "up", backlog: 3, failed: 0, retriesToday: 1, backlogWarningThreshold: 100 },
};

const clubs: AdminClubsResponse = {
  items: [
    {
      id: "club-1",
      name: "Basket Club des Lacs",
      slug: "basket-club-des-lacs",
      ffbbClubCode: "ARA001",
      planId: null,
      billingCycle: null,
      generationCountSeason: 3,
      createdAt: "2026-06-01T09:00:00+00:00",
      lastActivityAt: "2026-07-16T08:00:00+00:00",
      unsubscribed: false,
      currentSeason: { id: "season-1", name: "2026-2027", status: "DRAFT" },
      volumes: { teams: 12, venues: 3, coaches: 8, constraints: 29 },
      solver: {
        generations: 8,
        infeasible: 1,
        infeasibleRate: 0.125,
        p50WallTimeMs: 780,
        p95WallTimeMs: 1900,
        latestStatus: "COMPLETED",
        latestAt: "2026-07-16T08:10:00+00:00",
      },
    },
  ],
  pagination: { page: 1, limit: 25, total: 26, pages: 2 },
  metricsWindowDays: 30,
};

const jobs: AdminJobsResponse = {
  items: [
    {
      key: "period-reminders",
      label: "Rappels de périodes",
      command: "app:periods:remind",
      cadence: "daily",
      manualTriggerAllowed: false,
      nextRunAt: "2099-07-17T08:00:00+02:00",
      latestRun: {
        id: "run-1",
        status: "succeeded",
        source: "scheduled",
        startedAt: "2026-07-16T10:00:00+00:00",
        finishedAt: "2026-07-16T10:00:01+00:00",
        durationMs: 950,
        exitCode: 0,
      },
    },
    {
      key: "purge-seasons",
      label: "Purge des anciennes saisons",
      command: "app:seasons:purge",
      cadence: "quarterly",
      manualTriggerAllowed: false,
      nextRunAt: "2099-10-01T03:00:00+02:00",
      latestRun: null,
    },
    {
      key: "import-school-holidays",
      label: "Import des vacances scolaires",
      command: "app:school-holidays:import",
      cadence: "quarterly",
      manualTriggerAllowed: true,
      nextRunAt: "2099-10-01T04:00:00+02:00",
      latestRun: null,
    },
  ],
};

const mockOverview = vi.mocked(getAdminOverview);
const mockHealth = vi.mocked(getAdminHealth);
const mockJobs = vi.mocked(getAdminJobs);
const mockClubs = vi.mocked(getAdminClubs);
const mockRunJob = vi.mocked(runAdminJob);

describe("AdminDashboardPage", () => {
  beforeEach(() => {
    mockOverview.mockReset().mockResolvedValue(overview);
    mockHealth.mockReset().mockResolvedValue(health);
    mockJobs.mockReset().mockResolvedValue(jobs);
    mockClubs.mockReset().mockResolvedValue(clubs);
    mockRunJob.mockReset().mockResolvedValue({ key: "import-school-holidays", status: "succeeded", exitCode: 0 });
    useAdminStore.setState({ identity: { id: "admin-1", email: "ops@example.test" }, csrfToken: "csrf-123" });
  });

  it("renders fleet, health and club data from the SA2 APIs", async () => {
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    expect(await screen.findByText("Basket Club des Lacs")).toBeInTheDocument();
    expect(screen.getByText("42")).toBeInTheDocument();
    expect(screen.getByText("Base de données")).toBeInTheDocument();
    expect(screen.getByText("Découverte")).toBeInTheDocument();
    expect(screen.getByText("Rappels de périodes")).toBeInTheDocument();
    expect(screen.getByText("Quotidien")).toBeInTheDocument();
    expect(screen.getByRole("columnheader", { name: "Prochain passage" })).toBeInTheDocument();
    expect(screen.getByText("Réussi")).toBeInTheDocument();
    expect(screen.getAllByText("Jamais exécuté")).toHaveLength(2);
    expect(mockOverview).toHaveBeenCalledOnce();
    expect(mockHealth).toHaveBeenCalledOnce();
    expect(mockJobs).toHaveBeenCalledOnce();
    expect(mockClubs).toHaveBeenCalledWith(1, 25, "");
  });

  it("renders the usage stats: plans by type, time-to-close and club sizes (SA2-stats)", async () => {
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    expect(await screen.findByText("Plans, clôtures et tailles de clubs")).toBeInTheDocument();
    // Plans par type (libellés FR — « Saison » apparaît aussi dans la carte solveur) + « dont validés ».
    expect(screen.getAllByText("Saison").length).toBeGreaterThanOrEqual(2);
    expect(screen.getByText("Vacances")).toBeInTheDocument();
    expect(screen.getByText(/dont 11 validés/)).toBeInTheDocument();
    // Temps de clôture : création → 1re validation (36 h médiane saison ; P95 320 h → « 13 j » ;
    // une clôture de période en 25 min s'affiche en minutes, plus jamais « 0 h »).
    expect(screen.getByText("36 h")).toBeInTheDocument();
    expect(screen.getByText("13 j")).toBeInTheDocument();
    expect(screen.getByText("25 min")).toBeInTheDocument();
    expect(screen.getByText(/11 saisons · 9 périodes clôturées/)).toBeInTheDocument();
    // Tailles de clubs : tranches + médiane gymnases.
    expect(screen.getByRole("columnheader", { name: "Gymnases (médiane)" })).toBeInTheDocument();
    expect(screen.getByText("11-20")).toBeInTheDocument();
  });

  it("shows the usage panel as unavailable (never crashes) when the backend predates the usage block", async () => {
    // Rollback backend / décalage de déploiement : l'ancien overview n'a pas `usage`.
    const legacyOverview: AdminOverviewResponse = { clubs: overview.clubs, solver: overview.solver };
    mockOverview.mockReset().mockResolvedValue(legacyOverview);
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    expect(await screen.findByText("Les statistiques d’usage sont indisponibles.")).toBeInTheDocument();
    expect(screen.queryByText("Plans, clôtures et tailles de clubs")).not.toBeInTheDocument();
  });

  it("searches and paginates through the clubs API", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });
    await screen.findByText("Basket Club des Lacs");

    await user.type(screen.getByRole("searchbox", { name: /rechercher un club/i }), "  Lacs  ");
    await user.click(screen.getByRole("button", { name: "Rechercher" }));
    await waitFor(() => expect(mockClubs).toHaveBeenCalledWith(1, 25, "Lacs"));

    await user.click(screen.getByRole("button", { name: "Page suivante" }));
    await waitFor(() => expect(mockClubs).toHaveBeenCalledWith(2, 25, "Lacs"));
  }, 10_000);

  it("refreshes all four monitoring feeds on demand", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });
    await screen.findByText("Basket Club des Lacs");

    await user.click(screen.getByRole("button", { name: "Actualiser" }));

    await waitFor(() => {
      expect(mockOverview).toHaveBeenCalledTimes(2);
      expect(mockHealth).toHaveBeenCalledTimes(2);
      expect(mockJobs).toHaveBeenCalledTimes(2);
      expect(mockClubs).toHaveBeenCalledTimes(2);
    });
  });

  it("confirms and runs only a manually allowed reference import", async () => {
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });
    await screen.findByText("Import des vacances scolaires");

    expect(screen.getAllByText("Supervision seule")).toHaveLength(2);
    await user.click(screen.getByRole("button", { name: "Relancer" }));
    expect(screen.getByRole("dialog", { name: "Relancer cet import ?" })).toBeInTheDocument();
    expect(mockRunJob).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: "Relancer l’import" }));
    await waitFor(() => expect(mockRunJob).toHaveBeenCalledWith("import-school-holidays", "csrf-123"));
    await waitFor(() => expect(mockJobs).toHaveBeenCalledTimes(2));
  });

  it("keeps the other monitoring panels visible when health fails", async () => {
    mockHealth.mockRejectedValue(new Error("health unavailable"));
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    expect(await screen.findByText("La santé technique est indisponible.")).toBeInTheDocument();
    expect(screen.getByText("Basket Club des Lacs")).toBeInTheDocument();
    expect(screen.getByText("Activité globale")).toBeInTheDocument();
  });

  it("keeps the other monitoring panels visible when jobs fail", async () => {
    mockJobs.mockRejectedValue(new Error("jobs unavailable"));
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    expect(await screen.findByText("Les jobs opérationnels sont indisponibles.")).toBeInTheDocument();
    expect(screen.getByText("Basket Club des Lacs")).toBeInTheDocument();
    expect(screen.getByText("Santé technique")).toBeInTheDocument();
  });

  it("shows an explicit empty search result", async () => {
    mockClubs.mockResolvedValue({ ...clubs, items: [], pagination: { ...clubs.pagination, total: 0, pages: 0 } });
    const user = userEvent.setup();
    renderWithProviders(<AdminDashboardPage />, { route: "/admin" });

    await user.type(screen.getByRole("searchbox", { name: /rechercher un club/i }), "introuvable");
    await user.click(screen.getByRole("button", { name: "Rechercher" }));

    expect(await screen.findByText("Aucun club ne correspond à « introuvable »." )).toBeInTheDocument();
  });

  it("has no structural accessibility violations with populated data", async () => {
    const { container } = renderWithProviders(<AdminDashboardPage />, { route: "/admin" });
    await screen.findByText("Basket Club des Lacs");

    expect(await axe(container)).toHaveNoViolations();
  }, 10_000);
});
