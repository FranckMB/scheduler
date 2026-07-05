import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { Schedule } from "./api";
import { PlanningToolbar } from "./PlanningToolbar";

const noop = () => {};

const schedule = (status: Schedule["status"]): Schedule => ({ id: "s1", name: "Plan A", status, score: 100, createdAt: "2026-01-01", updatedAt: "2026-01-01", calendarEntryId: null });

function renderToolbar(s: Schedule, baselineScheduleId: string | null = null) {
  return render(
    <PlanningToolbar
      schedules={[s]}
      selectedScheduleId="s1"
      onSelectSchedule={noop}
      viewMode="gymnase"
      onViewMode={noop}
      onRegenerate={noop}
      onValidate={noop}
      onReopen={noop}
      onSetBaseline={noop}
      onRename={noop}
      isGenerating={false}
      actionBusy={false}
      baselineScheduleId={baselineScheduleId}
    />,
  );
}

describe("PlanningToolbar — schedule lifecycle (N3)", () => {
  it("offers Valider + a Régénérer button on a completed schedule", () => {
    renderToolbar(schedule("COMPLETED"));
    expect(screen.getByRole("button", { name: /valider/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /régénérer/i })).toBeInTheDocument();
    expect(screen.getByText("Terminé")).toBeInTheDocument();
  });

  it("offers Rouvrir, shows Validé, and hides Régénérer on a validated (read-only) schedule", () => {
    renderToolbar(schedule("VALIDATED"));
    expect(screen.getByRole("button", { name: /rouvrir/i })).toBeInTheDocument();
    expect(screen.getByText("Validé")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /régénérer/i })).not.toBeInTheDocument();
  });

  it("marks the baseline schedule as « Planning principal »", () => {
    renderToolbar(schedule("COMPLETED"), "s1");
    expect(screen.getByText("Planning principal")).toBeInTheDocument();
  });

  it("marks a non-baseline schedule as « Secondaire »", () => {
    renderToolbar(schedule("COMPLETED"), "other");
    expect(screen.getByText("Secondaire")).toBeInTheDocument();
  });
});
