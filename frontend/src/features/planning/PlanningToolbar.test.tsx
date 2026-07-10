import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { Schedule } from "./api";
import { PlanningToolbar } from "./PlanningToolbar";

const noop = () => {};

const schedule = (status: Schedule["status"]): Schedule => ({ id: "s1", name: "Plan A", status, score: 100, createdAt: "2026-01-01", updatedAt: "2026-01-01", calendarEntryId: null, generatedTeamCount: 12 });

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
      onDelete={noop}
      onRegenerateFrom={noop}
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
    expect(screen.getByRole("button", { name: "Régénérer" })).toBeInTheDocument();
    expect(screen.getByText("Terminé")).toBeInTheDocument();
  });

  it("offers Rouvrir, shows Validé, and hides Régénérer on a validated (read-only) schedule", () => {
    renderToolbar(schedule("VALIDATED"));
    expect(screen.getByRole("button", { name: /rouvrir/i })).toBeInTheDocument();
    expect(screen.getByText("Validé")).toBeInTheDocument();
    // The plain "Régénérer" (current structure) is hidden on a read-only version;
    // "Régénérer aux conditions" (restore this version's structure) may still show.
    expect(screen.queryByRole("button", { name: "Régénérer" })).not.toBeInTheDocument();
  });

  it("marks the baseline schedule as « Planning principal »", () => {
    renderToolbar(schedule("COMPLETED"), "s1");
    expect(screen.getByText("Planning principal")).toBeInTheDocument();
  });

  it("labels the version « V1 — … » and offers no rename control (versions are not renamable)", () => {
    renderToolbar(schedule("COMPLETED"));
    expect(screen.getByRole("option", { name: /^V1 — / })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /renommer/i })).not.toBeInTheDocument();
  });

  it("offers Supprimer on a plain work version, but not on the baseline", () => {
    renderToolbar(schedule("COMPLETED"), "other");
    expect(screen.getByRole("button", { name: /supprimer cette version/i })).toBeInTheDocument();
  });

  it("offers « Régénérer aux conditions » on a finished version that has a structure photo", () => {
    renderToolbar(schedule("COMPLETED"));
    expect(screen.getByRole("button", { name: /régénérer aux conditions/i })).toBeInTheDocument();
  });

  it("hides Supprimer on the baseline and on a validated version", () => {
    renderToolbar(schedule("COMPLETED"), "s1");
    expect(screen.queryByRole("button", { name: /supprimer cette version/i })).not.toBeInTheDocument();
    renderToolbar(schedule("VALIDATED"), "other");
    expect(screen.queryByRole("button", { name: /supprimer cette version/i })).not.toBeInTheDocument();
  });
});
