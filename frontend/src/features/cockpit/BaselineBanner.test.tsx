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

function renderBanner() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <BaselineBanner schedules={[baseline]} baselineScheduleId="b1" socleValidated />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("BaselineBanner — destructive reopen", () => {
  beforeEach(() => reopenMutate.mockReset());

  it("confirms first, then reopens when there are no overlays", async () => {
    reopenMutate.mockImplementation((_vars, opts) => opts?.onSuccess?.());
    renderBanner();
    // "Modifier…" opens a confirmation first (never a silent un-validate).
    await userEvent.click(screen.getByRole("button", { name: "Modifier…" }));
    expect(reopenMutate).not.toHaveBeenCalled();
    await userEvent.click(screen.getByRole("button", { name: "Modifier" }));
    expect(reopenMutate).toHaveBeenCalledWith({ id: "b1" }, expect.anything());
  });

  it("escalates to a proportional confirm on 409, then re-sends with confirm", async () => {
    reopenMutate.mockImplementationOnce((_vars, opts) => opts?.onError?.(new OverlaysExistError(2, [])));
    renderBanner();
    await userEvent.click(screen.getByRole("button", { name: "Modifier…" }));
    await userEvent.click(screen.getByRole("button", { name: "Modifier" }));
    expect(screen.getByText(/supprimera 2 plannings secondaires/i)).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Modifier et supprimer" }));
    expect(reopenMutate).toHaveBeenLastCalledWith({ id: "b1", confirmDeleteOverlays: true }, expect.anything());
  });
});
