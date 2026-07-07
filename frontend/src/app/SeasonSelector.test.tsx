import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { MeResponse, MeSeason } from "@/features/auth/api";
import { useSeasonStore } from "@/shared/stores/seasonStore";
import { useWizardStore } from "@/features/wizard/store";
import { SeasonSelector } from "./SeasonSelector";

let meData: Partial<MeResponse> | undefined;
const transitionMock = vi.fn();

vi.mock("@/features/auth/queries", () => ({
  useMe: () => ({ data: meData }),
}));
vi.mock("@/features/auth/api", () => ({
  transitionSeason: (id: string) => transitionMock(id),
}));
// The re-dating step has its own tests; here we only assert WHEN it opens.
vi.mock("@/features/season-transition/RedateEventsDialog", () => ({
  RedateEventsDialog: ({ sourceSeasonId, targetSeasonId }: { sourceSeasonId: string; targetSeasonId: string }) => (
    <div data-testid="redate-dialog">{`${sourceSeasonId}->${targetSeasonId}`}</div>
  ),
}));

const season = (overrides: Partial<MeSeason>): MeSeason => ({
  id: "sN",
  name: "2025-2026",
  startDate: "2025-08-01",
  endDate: "2026-07-15",
  isCurrent: true,
  isReadonly: false,
  ...overrides,
});

let queryClient: QueryClient;

function renderSelector() {
  queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <SeasonSelector />
    </QueryClientProvider>,
  );
}

describe("SeasonSelector", () => {
  beforeEach(() => {
    meData = undefined;
    transitionMock.mockReset();
    useSeasonStore.getState().clear();
  });

  it("renders nothing without seasons", () => {
    meData = { seasons: [], currentSeasonId: null };
    renderSelector();
    expect(screen.queryByText("Saison de travail")).not.toBeInTheDocument();
  });

  it("lists the seasons with their state badges", async () => {
    meData = {
      currentSeasonId: "sN",
      seasons: [
        season({ id: "sP", name: "2024-2025", isCurrent: false, isReadonly: true }),
        season({}),
        season({ id: "sD", name: "2026-2027", isCurrent: false }),
      ],
    };
    renderSelector();

    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    expect(screen.getByText("2024-2025 · lecture seule")).toBeInTheDocument();
    expect(screen.getByText("2025-2026 · en cours")).toBeInTheDocument();
    expect(screen.getByText("2026-2027 · brouillon")).toBeInTheDocument();
  });

  it("switching season sets the store, resets the wizard and clears the query cache", async () => {
    meData = { currentSeasonId: "sN", seasons: [season({}), season({ id: "sD", name: "2026-2027", isCurrent: false })] };
    useWizardStore.setState({ mode: "period", calendarEntryId: "ce1" });
    const clearSpy = vi.spyOn(QueryClient.prototype, "clear");
    renderSelector();

    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    await userEvent.click(screen.getByText("2026-2027 · brouillon"));

    expect(useSeasonStore.getState().selectedSeasonId).toBe("sD");
    expect(useWizardStore.getState().mode).toBe("season");
    expect(clearSpy).toHaveBeenCalled();
    clearSpy.mockRestore();
  });

  it("selecting the current season goes back to the default state (no header)", async () => {
    meData = { currentSeasonId: "sN", seasons: [season({}), season({ id: "sD", name: "2026-2027", isCurrent: false })] };
    useSeasonStore.getState().setSelectedSeasonId("sD");
    renderSelector();

    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    await userEvent.click(screen.getByText("2025-2026 · en cours"));

    expect(useSeasonStore.getState().selectedSeasonId).toBeNull();
  });

  it("lets the user switch into a read-only season for consultation (server guards writes)", async () => {
    meData = {
      currentSeasonId: "sN",
      seasons: [season({ id: "sP", name: "2024-2025", isCurrent: false, isReadonly: true }), season({})],
    };
    renderSelector();

    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    await userEvent.click(screen.getByText("2024-2025 · lecture seule"));

    expect(useSeasonStore.getState().selectedSeasonId).toBe("sP");
  });

  it("resets a stale persisted selection to the current season", () => {
    meData = { currentSeasonId: "sN", seasons: [season({}), season({ id: "sD", name: "2026-2027", isCurrent: false })] };
    useSeasonStore.getState().setSelectedSeasonId("purged-season");
    renderSelector();

    expect(useSeasonStore.getState().selectedSeasonId).toBeNull();
  });

  it("prepares the next season after confirmation and switches to it", async () => {
    // The freshly created season is already in me.seasons (refetched) so the
    // stale-selection guard does not reset it.
    meData = { currentSeasonId: "sN", seasons: [season({}), season({ id: "sD", name: "2026-2027", isCurrent: false })] };
    transitionMock.mockResolvedValue({ seasonId: "sD", name: "2026-2027", startDate: "2026-08-01", endDate: "2027-07-15", counts: {} });
    renderSelector();

    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    await userEvent.click(screen.getByText("Préparer la saison suivante…"));
    // Structural action → confirmation first, nothing fired yet.
    expect(transitionMock).not.toHaveBeenCalled();

    await userEvent.click(screen.getByRole("button", { name: "Préparer" }));
    expect(transitionMock).toHaveBeenCalledWith("sN");
    await waitFor(() => expect(useSeasonStore.getState().selectedSeasonId).toBe("sD"));
    // The re-dating step opens right after the switch, sourced from N.
    expect(screen.getByTestId("redate-dialog")).toHaveTextContent("sN->sD");
  });

  it("re-opens the re-dating step on the existing-successor path (409)", async () => {
    meData = { currentSeasonId: "sN", seasons: [season({}), season({ id: "sD", name: "2026-2027", isCurrent: false })] };
    const { HTTPError } = await import("ky");
    const response = new Response(JSON.stringify({ existingSeasonId: "sD" }), { status: 409 });
    const httpError = new HTTPError(response, new Request("http://t/api/seasons/sN/transition"), {} as never);
    // ky 2.x consumes the response stream and exposes the parsed body as
    // error.data — mirror that contract here.
    (httpError as unknown as { data: unknown }).data = { existingSeasonId: "sD" };
    transitionMock.mockRejectedValue(httpError);
    renderSelector();

    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    await userEvent.click(screen.getByText("Préparer la saison suivante…"));
    await userEvent.click(screen.getByRole("button", { name: "Préparer" }));

    await waitFor(() => expect(useSeasonStore.getState().selectedSeasonId).toBe("sD"));
    expect(screen.getByTestId("redate-dialog")).toHaveTextContent("sN->sD");
  });

  it("ignores a double-click on the confirm button (single transition request)", async () => {
    meData = { currentSeasonId: "sN", seasons: [season({}), season({ id: "sD", name: "2026-2027", isCurrent: false })] };
    let resolve: (v: unknown) => void = () => {};
    transitionMock.mockReturnValue(new Promise((r) => (resolve = r)));
    renderSelector();

    await userEvent.click(screen.getByRole("button", { name: "Saison de travail" }));
    await userEvent.click(screen.getByText("Préparer la saison suivante…"));
    const confirm = screen.getByRole("button", { name: "Préparer" });
    await userEvent.click(confirm);
    await userEvent.click(confirm); // second click while the first is in flight

    resolve({ seasonId: "sD", name: "2026-2027", startDate: "2026-08-01", endDate: "2027-07-15", counts: {} });
    await waitFor(() => expect(transitionMock).toHaveBeenCalledTimes(1));
  });
});
