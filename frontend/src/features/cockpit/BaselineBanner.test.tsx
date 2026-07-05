import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { OverlaysExistError, type Schedule } from "@/features/planning/api";

import { BaselineBanner } from "./BaselineBanner";

const reopenMutate = vi.fn();

vi.mock("@/features/planning/queries", () => ({
  useReopenSchedule: () => ({ mutate: reopenMutate, isPending: false }),
  useSetBaseline: () => ({ mutate: vi.fn(), isPending: false }),
}));

const baseline: Schedule = { id: "b1", name: "Socle", status: "VALIDATED", score: 9011, createdAt: "", updatedAt: "", calendarEntryId: null };

function renderBanner(overlayCount: number) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <BaselineBanner schedules={[baseline]} baselineScheduleId="b1" overlayCount={overlayCount} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("BaselineBanner — destructive reopen", () => {
  beforeEach(() => reopenMutate.mockReset());

  it("reopens directly when there are no overlays (no confirm dialog)", async () => {
    reopenMutate.mockImplementation((_vars, opts) => opts?.onSuccess?.());
    renderBanner(0);
    await userEvent.click(screen.getByRole("button", { name: "Modifier…" }));
    // First call = the no-confirm reopen; no destructive dialog shown.
    expect(reopenMutate).toHaveBeenCalledWith({ id: "b1" }, expect.anything());
    expect(screen.queryByText(/supprimera/i)).not.toBeInTheDocument();
  });

  it("shows a proportional confirm on 409, then re-sends with confirm", async () => {
    reopenMutate.mockImplementationOnce((_vars, opts) => opts?.onError?.(new OverlaysExistError(2, [])));
    renderBanner(2);
    await userEvent.click(screen.getByRole("button", { name: "Modifier…" }));
    expect(screen.getByText(/supprimera 2 calendriers secondaires/i)).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Modifier et supprimer" }));
    expect(reopenMutate).toHaveBeenLastCalledWith({ id: "b1", confirmDeleteOverlays: true }, expect.anything());
  });
});
