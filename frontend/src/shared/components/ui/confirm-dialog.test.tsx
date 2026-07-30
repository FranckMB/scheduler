import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { ConfirmDialog } from "./confirm-dialog";

const PHRASE = "modifier mon planning de saison";

describe("ConfirmDialog", () => {
  it("without confirmPhrase, the confirm button is enabled (additive — no behaviour change)", () => {
    render(<ConfirmDialog open title="Sûr ?" confirmLabel="Confirmer" onConfirm={vi.fn()} onCancel={vi.fn()} />);
    expect(screen.getByRole("button", { name: "Confirmer" })).toBeEnabled();
    // Pas de champ de saisie sans confirmPhrase.
    expect(screen.queryByRole("textbox")).not.toBeInTheDocument();
  });

  it("with confirmPhrase, the confirm button is disabled while nothing is typed", () => {
    render(
      <ConfirmDialog open title="Sûr ?" confirmLabel="Confirmer" confirmPhrase={PHRASE} onConfirm={vi.fn()} onCancel={vi.fn()} />,
    );
    expect(screen.getByRole("button", { name: "Confirmer" })).toBeDisabled();
    expect(screen.getByRole("textbox")).toBeInTheDocument();
  });

  it("stays disabled with a wrong phrase", async () => {
    const user = userEvent.setup();
    render(
      <ConfirmDialog open title="Sûr ?" confirmLabel="Confirmer" confirmPhrase={PHRASE} onConfirm={vi.fn()} onCancel={vi.fn()} />,
    );
    await user.type(screen.getByRole("textbox"), "autre chose");
    expect(screen.getByRole("button", { name: "Confirmer" })).toBeDisabled();
  });

  it("becomes enabled with the right phrase (trim + case-insensitive)", async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    render(
      <ConfirmDialog open title="Sûr ?" confirmLabel="Confirmer" confirmPhrase={PHRASE} onConfirm={onConfirm} onCancel={vi.fn()} />,
    );
    // Casse différente + espaces autour : la comparaison normalise.
    await user.type(screen.getByRole("textbox"), `  MODIFIER Mon Planning De Saison  `);
    const button = screen.getByRole("button", { name: "Confirmer" });
    expect(button).toBeEnabled();
    await user.click(button);
    expect(onConfirm).toHaveBeenCalledOnce();
  });

  it("resets the typed phrase when the dialog is closed and reopened", async () => {
    const user = userEvent.setup();
    const { rerender } = render(
      <ConfirmDialog open title="Sûr ?" confirmLabel="Confirmer" confirmPhrase={PHRASE} onConfirm={vi.fn()} onCancel={vi.fn()} />,
    );
    await user.type(screen.getByRole("textbox"), PHRASE);
    expect(screen.getByRole("button", { name: "Confirmer" })).toBeEnabled();

    // Fermeture puis réouverture : la saisie repart vide, le bouton est de nouveau grisé.
    rerender(
      <ConfirmDialog open={false} title="Sûr ?" confirmLabel="Confirmer" confirmPhrase={PHRASE} onConfirm={vi.fn()} onCancel={vi.fn()} />,
    );
    rerender(
      <ConfirmDialog open title="Sûr ?" confirmLabel="Confirmer" confirmPhrase={PHRASE} onConfirm={vi.fn()} onCancel={vi.fn()} />,
    );
    expect(screen.getByRole("textbox")).toHaveValue("");
    expect(screen.getByRole("button", { name: "Confirmer" })).toBeDisabled();
  });

  // Revue #326 : une confirmation qui ÉCHOUE ne ferme pas le dialogue (500, réseau, JWT
  // expiré — `reopen()` ne vide `reopenOverlayCount` que dans onSuccess). Sans ré-armement,
  // la phrase déjà tapée laissait le geste destructif à un clic pour la tentative suivante :
  // la friction était dépensée une fois pour toutes.
  it("re-arms the phrase after a confirm attempt that leaves the dialog open", async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    render(
      <ConfirmDialog open title="Sûr ?" confirmLabel="Confirmer" confirmPhrase={PHRASE} onConfirm={onConfirm} onCancel={vi.fn()} />,
    );
    await user.type(screen.getByRole("textbox"), PHRASE);
    await user.click(screen.getByRole("button", { name: "Confirmer" }));
    expect(onConfirm).toHaveBeenCalledOnce();

    // Le dialogue est toujours ouvert (l'appelant n'a pas basculé `open`) : le champ est
    // revidé et le bouton de nouveau grisé — il faut retaper pour réessayer.
    expect(screen.getByRole("textbox")).toHaveValue("");
    expect(screen.getByRole("button", { name: "Confirmer" })).toBeDisabled();
  });
});
