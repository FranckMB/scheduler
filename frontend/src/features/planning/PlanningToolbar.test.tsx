import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { Schedule } from "./api";
import { PlanningToolbar } from "./PlanningToolbar";

const noop = () => {};

const schedule = (status: Schedule["status"], over: Partial<Schedule> = {}): Schedule => ({ id: "s1", name: "Plan A", status, score: 100, createdAt: "2026-01-01", updatedAt: "2026-01-01", calendarEntryId: null, generatedTeamCount: 12, hasStructurePhoto: true, isLiveContext: true, ...over });

function renderToolbar(schedules: Schedule | Schedule[], { baselineScheduleId = null, embedded = true, selectedScheduleId = "s1" }: { baselineScheduleId?: string | null; embedded?: boolean; selectedScheduleId?: string } = {}) {
  return render(
    <PlanningToolbar
      schedules={Array.isArray(schedules) ? schedules : [schedules]}
      selectedScheduleId={selectedScheduleId}
      onSelectSchedule={noop}
      viewMode="gymnase"
      onViewMode={noop}
      onRegenerate={noop}
      onValidate={noop}
      onReopen={noop}
      onDelete={noop}
      onRegenerateFrom={noop}
      isGenerating={false}
      actionBusy={false}
      baselineScheduleId={baselineScheduleId}
      embedded={embedded}
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
    // "Charger cette version" (restore this version's structure) may still show.
    expect(screen.queryByRole("button", { name: "Régénérer" })).not.toBeInTheDocument();
  });

  it("never offers a « Définir principal » action (the main plan is the first validated one, not a choice)", () => {
    renderToolbar(schedule("COMPLETED"), { baselineScheduleId: "other" });
    expect(screen.queryByRole("button", { name: /principal/i })).not.toBeInTheDocument();
  });

  it("stars the loaded-context (isLiveContext) version in the selector", () => {
    renderToolbar(schedule("COMPLETED"));
    expect(screen.getByRole("option", { name: /★/ })).toBeInTheDocument();
  });

  it("stars exactly ONE version — falls back to the latest when no pointer is set", () => {
    // Neither carries the server pointer → fallback to the latest visible (s2).
    renderToolbar([schedule("COMPLETED", { id: "s1", createdAt: "2026-01-01", isLiveContext: false }), schedule("COMPLETED", { id: "s2", createdAt: "2026-02-01", isLiveContext: false })]);
    expect(screen.getAllByRole("option", { name: /★/ })).toHaveLength(1);
  });

  it("labels the version « V1 — … » and offers no rename control (versions are not renamable)", () => {
    renderToolbar(schedule("COMPLETED"));
    expect(screen.getByRole("option", { name: /^V1 — / })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /renommer/i })).not.toBeInTheDocument();
  });

  it("offers Supprimer on a plain work version, but not on the baseline", () => {
    renderToolbar(schedule("COMPLETED"), { baselineScheduleId: "other" });
    expect(screen.getByRole("button", { name: /supprimer cette version/i })).toBeInTheDocument();
  });

  it("greys « Charger cette version » on the live-context (★) version — reloading it is a no-op", () => {
    // A single schedule is necessarily the live context (fallback to latest).
    renderToolbar(schedule("COMPLETED"));
    expect(screen.getByRole("button", { name: /charger cette version/i })).toBeDisabled();
  });

  it("enables « Charger cette version » on a non-live version", () => {
    // s1 (selected) is NOT the loaded context — s2 carries the ★.
    renderToolbar([schedule("COMPLETED", { id: "s1", createdAt: "2026-01-01", isLiveContext: false }), schedule("COMPLETED", { id: "s2", createdAt: "2026-02-01", isLiveContext: true })]);
    expect(screen.getByRole("button", { name: /charger cette version/i })).toBeEnabled();
  });

  it("disables « Régénérer » during a « Charger » restore (actionBusy) without showing « Génération… »", () => {
    render(
      <PlanningToolbar
        schedules={[schedule("COMPLETED")]}
        selectedScheduleId="s1"
        onSelectSchedule={noop}
        viewMode="gymnase"
        onViewMode={noop}
        onRegenerate={noop}
        onValidate={noop}
        onReopen={noop}
        onDelete={noop}
        onRegenerateFrom={noop}
        isGenerating={false}
        actionBusy
        baselineScheduleId={null}
        embedded
      />,
    );
    const regen = screen.getByRole("button", { name: "Régénérer" });
    expect(regen).toBeDisabled();
    // The busy label keys on isGenerating (false here), not actionBusy → no false spinner text.
    expect(screen.queryByRole("button", { name: /génération…/i })).not.toBeInTheDocument();
  });

  it("hides « Charger cette version » on a pre-D2 version with no structure photo (would 409)", () => {
    renderToolbar(schedule("COMPLETED", { hasStructurePhoto: false }));
    expect(screen.queryByRole("button", { name: /charger cette version/i })).not.toBeInTheDocument();
  });

  it("hides Supprimer on the baseline and on a validated version", () => {
    renderToolbar(schedule("COMPLETED"), { baselineScheduleId: "s1" });
    expect(screen.queryByRole("button", { name: /supprimer cette version/i })).not.toBeInTheDocument();
    renderToolbar(schedule("VALIDATED"), { baselineScheduleId: "other" });
    expect(screen.queryByRole("button", { name: /supprimer cette version/i })).not.toBeInTheDocument();
  });

  it("standalone /planning (not embedded) hides the version selector, status badge and score", () => {
    renderToolbar(schedule("COMPLETED"), { embedded: false });
    expect(screen.queryByRole("combobox", { name: /version du planning/i })).not.toBeInTheDocument();
    expect(screen.queryByText("Terminé")).not.toBeInTheDocument();
    expect(screen.queryByText(/score/i)).not.toBeInTheDocument();
    // View modes remain (consultation still switches gym/coach/team views).
    expect(screen.getByRole("button", { name: "Par coach" })).toBeInTheDocument();
  });
});
