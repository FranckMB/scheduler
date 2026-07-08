/**
 * WCAG 2.2 AA guardrail (structural). Renders shared primitives + key screens
 * and asserts axe-core finds no violations. Runs on every `vitest run`, so any
 * frontend change that regresses accessibility fails here.
 *
 * jsdom has no layout engine → axe skips colour-contrast (WCAG 1.4.3); that axis
 * is enforced by the Playwright/axe pass added in PR2 alongside the contrast
 * fixes (A11Y-06). The `describe.skip` blocks below are the KNOWN violations from
 * the 2026-07-08 audit — PR2 fixes each, then unskips it and this file becomes a
 * blocking regression net for the norm.
 */
import { describe, it } from "vitest";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";

import { expectNoA11yViolations } from "./utils";

describe("a11y — shared UI primitives (clean baseline)", () => {
  it("Button has no violations", async () => {
    await expectNoA11yViolations(<Button>Enregistrer</Button>);
  });

  it("labelled Input has no violations", async () => {
    await expectNoA11yViolations(
      <>
        <label htmlFor="team-name">Nom de l'équipe</label>
        <Input id="team-name" />
      </>,
    );
  });

  it("labelled Select has no violations", async () => {
    await expectNoA11yViolations(
      <Select aria-label="Gymnase">
        <option value="a">Armand</option>
        <option value="b">Mateo</option>
      </Select>,
    );
  });

  it("ConfirmDialog (open) has no violations", async () => {
    await expectNoA11yViolations(
      <ConfirmDialog open title="Supprimer cette contrainte ?" description="Action définitive." onConfirm={() => {}} onCancel={() => {}} />,
    );
  });
});

// --- Known audit violations — unskipped one by one in PR2 as each is fixed. ----

describe.skip("a11y — audit gaps (PR2)", () => {
  // A11Y-03 / FRT-12/13 / UXC-02: Modal has no focus-trap nor focus restoration.
  it.todo("Modal traps + restores focus (WCAG 2.4.3)");
  // A11Y-01: venue distinguished by colour only in the planning grid cells.
  it.todo("WeekGrid venue cells carry a text label, not colour only (WCAG 1.4.1)");
  // A11Y-05: MonthCalendar emoji carries info via title only (no role=img+aria-label).
  it.todo("MonthCalendar info emoji exposes a text alternative (WCAG 1.1.1)");
});
