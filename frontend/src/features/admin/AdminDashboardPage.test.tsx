import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { axe } from "vitest-axe";

import { renderWithProviders } from "@/test/utils";

import type { AdminClubsResponse, AdminHealthResponse, AdminJobsResponse, AdminOverviewResponse } from "./api";
import { getAdminClubs, getAdminHealth, getAdminJobs, getAdminOverview } from "./api";
import { AdminDashboardPage } from "./AdminDashboardPage";

vi.mock("./api", async (importOriginal) => {
  const original = await importOriginal<typeof import("./api")>();
  return {
    ...original,
    getAdminOverview: vi.fn(),
    getAdminHealth: vi.fn(),
    getAdminJobs: vi.fn(),
    getAdminClubs: vi.fn(),
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
      nextRunAt: "2099-10-01T03:00:00+02:00",
      latestRun: null,
    },
  ],
};

const mockOverview = vi.mocked(getAdminOverview);
const mockHealth = vi.mocked(getAdminHealth);
const mockJobs = vi.mocked(getAdminJobs);
const mockClubs = vi.mocked(getAdminClubs);

describe("AdminDashboardPage", () => {
  beforeEach(() => {
    mockOverview.mockReset().mockResolvedValue(overview);
    mockHealth.mockReset().mockResolvedValue(health);
    mockJobs.mockReset().mockResolvedValue(jobs);
    mockClubs.mockReset().mockResolvedValue(clubs);
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
    expect(screen.getByText("Jamais exécuté")).toBeInTheDocument();
    expect(mockOverview).toHaveBeenCalledOnce();
    expect(mockHealth).toHaveBeenCalledOnce();
    expect(mockJobs).toHaveBeenCalledOnce();
    expect(mockClubs).toHaveBeenCalledWith(1, 25, "");
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
