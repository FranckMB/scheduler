import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { WeekPickerDialog } from "./WeekPickerDialog";

const mother = { title: "Barros en travaux", startDate: "2026-11-12", endDate: "2026-11-18" };

const weeks = [
  { startDate: "2026-11-09", endDate: "2026-11-15", monday: "2026-11-09" },
  { startDate: "2026-11-16", endDate: "2026-11-22", monday: "2026-11-16" },
];

describe("WeekPickerDialog (P2-5 E1)", () => {
  it("prechecks every week and creates the picked ones", async () => {
    const user = userEvent.setup();
    const onPickWeeks = vi.fn();
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} onPickWeeks={onPickWeeks} onAdaptWhole={vi.fn()} onClose={vi.fn()} />);

    const boxes = screen.getAllByRole("checkbox");
    expect(boxes).toHaveLength(2);
    boxes.forEach((b) => expect(b).toBeChecked());

    // Décocher la semaine 2 → seule la semaine 1 est créée.
    await user.click(boxes[1]);
    await user.click(screen.getByRole("button", { name: /créer le planning de la semaine/i }));
    expect(onPickWeeks).toHaveBeenCalledWith([weeks[0]]);
  });

  it("keeps the whole-block path available (founder decision)", async () => {
    const user = userEvent.setup();
    const onAdaptWhole = vi.fn();
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} onPickWeeks={vi.fn()} onAdaptWhole={onAdaptWhole} onClose={vi.fn()} />);

    await user.click(screen.getByRole("button", { name: /d'un bloc/i }));
    expect(onAdaptWhole).toHaveBeenCalled();
  });

  it("disarms creation when nothing is picked", async () => {
    const user = userEvent.setup();
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} onPickWeeks={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} />);
    for (const b of screen.getAllByRole("checkbox")) {
      await user.click(b);
    }
    expect(screen.getByRole("button", { name: /créer/i })).toBeDisabled();
  });
});

// P2-38 PR3 — un refus « une seule planification par fenêtre » (409 window_already_planned) sur la
// création de semaines s'AFFICHE dans le picker (proposition, pas toast fugace) et propose d'ouvrir
// le planning en conflit — jamais réécrit côté front (le message vient du serveur).
describe("WeekPickerDialog — refus de chevauchement (P2-38)", () => {
  const conflict = {
    message: "Ces dates sont déjà planifiées par « Vacances de Toussaint » (du 19 octobre 2026 au 2 novembre 2026). Modifiez ce planning existant ou supprimez-le. Vous pouvez aussi découper la période en semaines.",
    entryId: "conflict-entry-9",
  };

  it("affiche le message du serveur et propose d'ouvrir le planning en place, sur son entryId", async () => {
    const user = userEvent.setup();
    const onOpenConflict = vi.fn();
    render(
      <WeekPickerDialog
        title={mother.title}
        startDate={mother.startDate}
        endDate={mother.endDate}
        weeks={weeks}
        busy={false}
        onPickWeeks={vi.fn()}
        onAdaptWhole={vi.fn()}
        onClose={vi.fn()}
        conflict={conflict}
        onOpenConflict={onOpenConflict}
      />,
    );

    // Le message SERVEUR est affiché tel quel (nomme la période + sa fenêtre + les issues).
    expect(screen.getByText(/déjà planifiées par « Vacances de Toussaint »/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /ouvrir le planning en place/i }));
    expect(onOpenConflict).toHaveBeenCalledWith("conflict-entry-9");
  });

  it("témoin : sans conflit, aucun bloc de refus (non-régression)", () => {
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} onPickWeeks={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} />);
    expect(screen.queryByRole("button", { name: /ouvrir le planning en place/i })).not.toBeInTheDocument();
  });
});
