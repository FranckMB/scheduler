/**
 * WCAG 2.2 AA guardrail (structural). Renders shared primitives + the Modal and
 * asserts axe-core finds no violations. Runs on every `vitest run`, so any
 * frontend change that regresses accessibility fails here. Component-specific
 * a11y (MonthCalendar emoji alternatives, WeekGrid venue-as-text) is asserted in
 * those components' own co-located tests, where the render fixtures already live.
 *
 * jsdom has no layout engine → axe skips colour-contrast (WCAG 1.4.3); that axis
 * is enforced by the Playwright/axe pass (follow-up) alongside the contrast fixes.
 */
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { axe } from "vitest-axe";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { Select } from "@/shared/components/ui/select";

import { expectNoA11yViolations } from "./utils";

describe("a11y — shared UI primitives", () => {
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

  it("ConfirmDialog focuses the panel (not the destructive button) and Escape cancels", async () => {
    const onConfirm = vi.fn();
    const onCancel = vi.fn();
    render(<ConfirmDialog open title="Supprimer ?" onConfirm={onConfirm} onCancel={onCancel} />);

    // Initial focus is the neutral panel, so a stray Enter never fires the
    // destructive action (the removed autoFocus was on the confirm button).
    expect(screen.getByRole("dialog")).toHaveFocus();
    await userEvent.keyboard("{Enter}");
    expect(onConfirm).not.toHaveBeenCalled();

    await userEvent.keyboard("{Escape}");
    expect(onCancel).toHaveBeenCalledOnce();
  });
});

describe("a11y — Modal focus management (WCAG 2.1.2 / 2.4.3)", () => {
  it("has no violations, moves focus into the panel, and closes on Escape", async () => {
    const onClose = vi.fn();
    render(
      <Modal label="Boîte de test" title="Titre" onClose={onClose}>
        <button type="button">Action</button>
      </Modal>,
    );

    const dialog = screen.getByRole("dialog");
    // Focus is moved into the dialog on open (neutral panel entry point).
    expect(dialog).toHaveFocus();
    // Modal portals to document.body, so scan the whole document.
    expect(await axe(document.body)).toHaveNoViolations();

    await userEvent.keyboard("{Escape}");
    expect(onClose).toHaveBeenCalledOnce();
  });

  it("Escape on a nested ConfirmDialog does not also close the surrounding Modal (review C1)", async () => {
    const onModalClose = vi.fn();
    const onConfirmCancel = vi.fn();
    render(
      <Modal label="Extérieur" title="Extérieur" onClose={onModalClose}>
        <button type="button">Contenu</button>
        <ConfirmDialog open title="Confirmer ?" onConfirm={() => {}} onCancel={onConfirmCancel} />
      </Modal>,
    );

    // Put focus on the inner confirm (its real state when stacked on the modal).
    // Escape must dismiss only it (panel-scoped listener), leaving the outer modal
    // open — a document-level listener fired both.
    screen.getByRole("dialog", { name: "Confirmer ?" }).focus();
    await userEvent.keyboard("{Escape}");
    expect(onConfirmCancel).toHaveBeenCalledOnce();
    expect(onModalClose).not.toHaveBeenCalled();
  });

  it("restores focus to the trigger element on close (WCAG 2.4.3)", () => {
    // The element focused when the modal opens is the one focus returns to on close.
    const trigger = document.createElement("button");
    trigger.textContent = "Trigger";
    document.body.appendChild(trigger);
    trigger.focus();
    expect(trigger).toHaveFocus();

    const { unmount } = render(
      <Modal label="Boîte" title="Titre" onClose={() => {}}>
        <button type="button">Action</button>
      </Modal>,
    );
    expect(screen.getByRole("dialog")).toHaveFocus();

    unmount();
    expect(trigger).toHaveFocus();
    trigger.remove();
  });
});
