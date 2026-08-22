import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { WizardStepLink } from "./WizardStepLink";
import { useWizardStore } from "./store";

// Statut de club piloté par test : établi (version finie) = navigation libre ; onboarding = guidé.
let me: { seasonPlan: { hasFinishedVersion: boolean } } | undefined = { seasonPlan: { hasFinishedVersion: true } };
vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: me }) }));

beforeEach(() => {
  me = { seasonPlan: { hasFinishedVersion: true } };
  useWizardStore.setState({ maxIndex: 0, deepLinkOrigin: null });
});

describe("WizardStepLink — deux régimes (P2-25)", () => {
  it("club établi : un VRAI lien vers l'étape ciblée, sans nouvel onglet", () => {
    renderWithProviders(
      <WizardStepLink step="venues" params={{ slot: "s1" }} from="reservation">
        Régler ce créneau
      </WizardStepLink>,
    );
    const link = screen.getByRole("link", { name: "Régler ce créneau" });
    const href = link.getAttribute("href") ?? "";
    expect(href).toContain("step=venues");
    expect(href).toContain("slot=s1");
    expect(href).toContain("from=reservation");
    // Nouvel onglet écarté (brutal sur mobile) : jamais de target=_blank.
    expect(link).not.toHaveAttribute("target");
  });

  it("mode guidé + étape verrouillée : DÉSACTIVÉ avec la raison VISIBLE, aucun lien (aucun saut)", () => {
    me = { seasonPlan: { hasFinishedVersion: false } }; // onboarding → guidé
    useWizardStore.setState({ maxIndex: 0 }); // seule « Équipes » atteinte → « Génération » verrouillée
    renderWithProviders(<WizardStepLink step="generate">Aller à la génération</WizardStepLink>);

    expect(screen.queryByRole("link")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Aller à la génération" })).toBeDisabled();
    // La raison est VISIBLE (pas seulement un title), et nomme l'étape à finir.
    expect(screen.getByText(/Terminez d'abord l'étape/)).toBeInTheDocument();
    expect(screen.getByText(/Équipes/)).toBeInTheDocument();
  });

  it("mode guidé mais étape déjà atteinte (≤ maxIndex) : lien actif", () => {
    me = { seasonPlan: { hasFinishedVersion: false } };
    useWizardStore.setState({ maxIndex: 3 }); // Contraintes atteinte → Gymnases (index 1) reste ouvert
    renderWithProviders(<WizardStepLink step="venues">Vers les gymnases</WizardStepLink>);
    expect(screen.getByRole("link", { name: "Vers les gymnases" })).toBeInTheDocument();
  });
});
