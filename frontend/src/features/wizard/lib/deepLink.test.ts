import { describe, expect, it } from "vitest";

import { isWizardStepId, parseWizardDeepLink, resolveOrigin, stepLockReason, wizardDeepLinkHref } from "./deepLink";

describe("wizard deep-link — contrat d'URL", () => {
  it("reconnaît une étape valide et rejette l'inconnue", () => {
    expect(isWizardStepId("venues")).toBe(true);
    expect(isWizardStepId("constraints")).toBe(true);
    expect(isWizardStepId("nope")).toBe(false);
    expect(isWizardStepId(null)).toBe(false);
  });

  it("parse `?step=venues&slot=X` → étape gymnases (la cible slot est lue par l'étape)", () => {
    const link = parseWizardDeepLink(new URLSearchParams("step=venues&slot=X"));
    expect(link.step).toBe("venues");
    expect(link.origin).toBeNull();
  });

  it("un `step` inconnu → step null (atterrissage propre, aucun saut)", () => {
    expect(parseWizardDeepLink(new URLSearchParams("step=zzz&slot=X")).step).toBeNull();
    // Sans step du tout : idem.
    expect(parseWizardDeepLink(new URLSearchParams("slot=X")).step).toBeNull();
  });

  it("résout l'origine de retour depuis `?from=`, ou null si inconnue", () => {
    expect(resolveOrigin("planning")?.label).toBe("Retour au planning");
    expect(resolveOrigin("reservation")?.label).toBe("Retour à la réservation");
    expect(resolveOrigin("bogus")).toBeNull();
    expect(resolveOrigin(null)).toBeNull();
  });

  it("le retour planning ramène au planning, le retour réservation à l'onglet Réserver", () => {
    expect(resolveOrigin("planning")?.returnTo).toBe("/planning");
    expect(resolveOrigin("reservation")?.returnTo).toContain("step=constraints");
    expect(resolveOrigin("reservation")?.returnTo).toContain("tab=reserve");
  });

  describe("mode guidé — une étape verrouillée ne saute pas", () => {
    it("hors mode guidé : jamais de verrou", () => {
      expect(stepLockReason("generate", { guided: false, maxIndex: 0 })).toBeNull();
    });

    it("guidé, étape déjà atteinte (index ≤ maxIndex) : atteignable", () => {
      // venues = index 1 ; maxIndex 3 → atteignable.
      expect(stepLockReason("venues", { guided: true, maxIndex: 3 })).toBeNull();
    });

    it("guidé, étape en avant (index > maxIndex) : verrouillée AVEC la raison nommant l'étape à finir", () => {
      // maxIndex 0 (Équipes) → viser Contraintes est verrouillé, et la raison nomme « Équipes ».
      const reason = stepLockReason("constraints", { guided: true, maxIndex: 0 });
      expect(reason).not.toBeNull();
      expect(reason).toContain("Équipes");
    });
  });

  it("construit une URL de deep-link avec cible et origine", () => {
    const href = wizardDeepLinkHref("venues", { slot: "s1" }, "reservation");
    expect(href).toContain("step=venues");
    expect(href).toContain("slot=s1");
    expect(href).toContain("from=reservation");
  });
});
