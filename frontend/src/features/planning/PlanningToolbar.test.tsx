import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { Schedule, ScheduleCapabilities } from "./api";
import { PlanningToolbar } from "./PlanningToolbar";

const noop = () => {};

// P2-8 : les gestes de la toolbar (Supprimer / Valider / Charger) sont désormais pilotés
// par le bloc `capabilities` SERVEUR, plus par un calcul client. Défaut = une version de
// travail terminée « ordinaire » ; chaque test surcharge le champ qui porte son scénario.
// `capabilities: null` force l'absence (réponse d'écriture / cache périmé → fail-closed).
const DEFAULT_CAPS: ScheduleCapabilities = { canDelete: false, canValidate: true, canRegenerateFrom: true, versionsDeletedOnValidate: 0, overlaysDroppedOnValidate: 0 };
type ScheduleOver = Partial<Omit<Schedule, "capabilities">> & { capabilities?: Partial<ScheduleCapabilities> | null };

const schedule = (status: Schedule["status"], over: ScheduleOver = {}): Schedule => {
  const { capabilities: capsOver, ...rest } = over;
  return { id: "s1", name: "Plan A", status, score: 100, createdAt: "2026-01-01", updatedAt: "2026-01-01", planType: "SEASON", schedulePlanId: "season-plan", generatedTeamCount: 12, hasStructurePhoto: true, isLiveContext: true, capabilities: null === capsOver ? null : { ...DEFAULT_CAPS, ...capsOver }, ...rest };
};

function renderToolbar(
  schedules: Schedule | Schedule[],
  {
    embedded = true,
    selectedScheduleId = "s1",
    disableRegenerate = false,
    slots = false,
    outputCredits = null,
    manualLockCount = 0,
    onOpenLocks,
  }: {
    embedded?: boolean;
    selectedScheduleId?: string;
    disableRegenerate?: boolean;
    slots?: boolean;
    outputCredits?: { count: number; blocked: boolean } | null;
    manualLockCount?: number;
    onOpenLocks?: () => void;
  } = {},
) {
  return render(
    <PlanningToolbar
      filterSlot={slots ? <span>FILTRE</span> : undefined}
      rightSlot={slots ? <span>EXPORT</span> : undefined}
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
      disableRegenerate={disableRegenerate}
      outputCredits={outputCredits}
      manualLockCount={manualLockCount}
      onOpenLocks={onOpenLocks}
      isGenerating={false}
      actionBusy={false}
      embedded={embedded}
    />,
  );
}

describe("PlanningToolbar — où vivent le filtre et l'export (P4-43)", () => {
  it("place le filtre AVEC le sélecteur de vue (ligne 1), et l'export avec les actions (ligne 2)", () => {
    // Le filtre vivait en ligne 2 derrière l'export, alors que son libellé SUIT le mode de
    // vue courant (« Par gymnase » → « Gymnases : … ») : deux contrôles sur les mêmes trois
    // ressources, à deux endroits éloignés, dont le second passait inaperçu. L'ordre du DOM
    // porte la règle — pas les classes, qu'un ajustement de style ferait rougir pour rien.
    renderToolbar(schedule("COMPLETED"), { slots: true });

    const view = screen.getByRole("button", { name: "Par gymnase" });
    const filter = screen.getByText("FILTRE");
    const exported = screen.getByText("EXPORT");
    const regenerate = screen.getByRole("button", { name: "Régénérer" });

    // Vue → filtre → actions : le filtre est monté avant la ligne d'actions.
    expect(view.compareDocumentPosition(filter) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(filter.compareDocumentPosition(regenerate) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    // L'export, lui, reste après les actions.
    expect(regenerate.compareDocumentPosition(exported) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });
});

describe("PlanningToolbar — coût en crédits sur « Régénérer » (P1-3 §4bis)", () => {
  it("affiche le solde sur « Régénérer » en Découverte bridée", () => {
    renderToolbar(schedule("COMPLETED"), { outputCredits: { count: 8, blocked: false } });
    expect(screen.getByRole("button", { name: "Régénérer (8)" })).toBeEnabled();
  });

  it("désactive « Régénérer (0) » quand le serveur ne laisse plus sortir", () => {
    renderToolbar(schedule("COMPLETED"), { outputCredits: { count: 0, blocked: true } });
    expect(screen.getByRole("button", { name: "Régénérer (0)" })).toBeDisabled();
  });

  it("aucun suffixe ni blocage hors Découverte bridée (outputCredits null)", () => {
    renderToolbar(schedule("COMPLETED"), { outputCredits: null });
    expect(screen.getByRole("button", { name: "Régénérer" })).toBeEnabled();
  });
});

describe("PlanningToolbar — survie des verrous à la régénération", () => {
  it("énonce près de « Régénérer » que les créneaux verrouillés sont conservés", () => {
    renderToolbar(schedule("COMPLETED"));
    // La garantie ne vivait qu'en commentaire de code ; elle est désormais dite à l'écran,
    // exactement là où la régénération se déclenche.
    expect(screen.getByText(/verrouillés sont conservés/i)).toBeInTheDocument();
  });

  it("n'affiche pas la phrase quand « Régénérer » est masqué (version en vigueur, lecture seule)", () => {
    renderToolbar(schedule("COMPLETED", { isChosen: true }));
    expect(screen.queryByText(/verrouillés sont conservés/i)).not.toBeInTheDocument();
  });
});

describe("PlanningToolbar — compteur de verrous manuels (PR 3)", () => {
  it("affiche « Verrous manuels (n) » et ouvre le panneau au clic", () => {
    const onOpenLocks = vi.fn();
    renderToolbar(schedule("COMPLETED"), { manualLockCount: 2, onOpenLocks });

    const btn = screen.getByRole("button", { name: /verrous manuels \(2\)/i });
    btn.click();
    expect(onOpenLocks).toHaveBeenCalled();
  });

  it("masque le compteur quand aucun verrou manuel (n = 0) — le plus sobre", () => {
    renderToolbar(schedule("COMPLETED"), { manualLockCount: 0, onOpenLocks: vi.fn() });
    expect(screen.queryByRole("button", { name: /verrous manuels/i })).not.toBeInTheDocument();
  });

  it("visible aussi en lecture seule (version en vigueur) : les verrous restent consultables", () => {
    renderToolbar(schedule("COMPLETED", { isChosen: true }), { manualLockCount: 3, onOpenLocks: vi.fn() });
    expect(screen.getByRole("button", { name: /verrous manuels \(3\)/i })).toBeInTheDocument();
  });
});

describe("PlanningToolbar — schedule lifecycle (N3)", () => {
  it("offers Valider + a Régénérer button on a completed schedule", () => {
    renderToolbar(schedule("COMPLETED"));
    expect(screen.getByRole("button", { name: /valider/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Régénérer" })).toBeInTheDocument();
    expect(screen.getByText("Terminé")).toBeInTheDocument();
  });

  it("offers Rouvrir and hides Régénérer on the version in force (read-only)", () => {
    // « En vigueur » is the plan's pointer (isChosen), not a status.
    renderToolbar(schedule("COMPLETED", { isChosen: true }));
    expect(screen.getByRole("button", { name: /rouvrir/i })).toBeInTheDocument();
    // The plain "Régénérer" (current structure) is hidden on a read-only version;
    // "Charger cette version" (restore this version's structure) may still show.
    expect(screen.queryByRole("button", { name: "Régénérer" })).not.toBeInTheDocument();
  });

  it("never offers a « Définir principal » action (the plan's pointer is moved by validating, not by a toggle)", () => {
    renderToolbar(schedule("COMPLETED"));
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

  it("offers Supprimer on a plain work version, but not on the one in force", () => {
    // Deux versions : celle qu'on regarde n'est pas la dernière, donc la saison reste
    // ancrée — le serveur la déclare supprimable (canDelete), c'est bien « une version
    // de travail ordinaire » qu'on teste.
    renderToolbar([schedule("COMPLETED", { id: "s1", capabilities: { canDelete: true } }), schedule("COMPLETED", { id: "s2", createdAt: "2026-02-01" })]);
    expect(screen.getByRole("button", { name: /supprimer cette version/i })).toBeInTheDocument();
  });

  it("hides Supprimer on the season's LAST finished version — it anchors the season (server refuses it)", () => {
    renderToolbar(schedule("COMPLETED"));
    expect(screen.queryByRole("button", { name: /supprimer cette version/i })).not.toBeInTheDocument();
  });

  it("hides Valider while a sibling is still solving — the server refuses the whole validation (canValidate:false)", () => {
    renderToolbar([schedule("COMPLETED", { id: "s1", capabilities: { canValidate: false } }), schedule("GENERATING", { id: "s2", createdAt: "2026-02-01" })]);
    expect(screen.queryByRole("button", { name: /valider/i })).not.toBeInTheDocument();
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
        embedded
      />,
    );
    const regen = screen.getByRole("button", { name: "Régénérer" });
    expect(regen).toBeDisabled();
    // The busy label keys on isGenerating (false here), not actionBusy → no false spinner text.
    expect(screen.queryByRole("button", { name: /génération…/i })).not.toBeInTheDocument();
  });

  it("greys « Régénérer » when the selected season version already matches the current structure", () => {
    renderToolbar(schedule("COMPLETED"), { disableRegenerate: true });
    expect(screen.getByRole("button", { name: "Régénérer" })).toBeDisabled();
  });

  it("hides « Charger cette version » on a pre-D2 version with no structure photo (server: canRegenerateFrom:false)", () => {
    renderToolbar(schedule("COMPLETED", { hasStructurePhoto: false, capabilities: { canRegenerateFrom: false } }));
    expect(screen.queryByRole("button", { name: /charger cette version/i })).not.toBeInTheDocument();
  });

  it("hides Supprimer on the version in force — whether the SEASON plan points at it or its own plan does", () => {
    // The season's calendar: /api/me carries that pointer.
    renderToolbar(schedule("COMPLETED", { isChosen: true }));
    expect(screen.queryByRole("button", { name: /supprimer cette version/i })).not.toBeInTheDocument();
    // A version its OWN plan points at (e.g. a period's overlay in force): the
    // season pointer is elsewhere, so only the per-version isChosen catches it.
    renderToolbar(schedule("COMPLETED", { isChosen: true }));
    expect(screen.queryByRole("button", { name: /supprimer cette version/i })).not.toBeInTheDocument();
  });

  it("fail-closed : capabilities absent (réponse d'écriture / cache périmé) → aucun geste destructif offert", () => {
    // P2-8 : un geste ne s'offre que sur `capabilities.* === true`. Sans le bloc (POST/PUT
    // rend `null`, ou cache périmé), on n'OFFRE PAS — jamais de défaut permissif : offrir
    // « Supprimer » puis se faire refuser en 409 est pire que ne pas l'offrir.
    renderToolbar(schedule("COMPLETED", { capabilities: null }));
    expect(screen.queryByRole("button", { name: /supprimer cette version/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /valider/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /charger cette version/i })).not.toBeInTheDocument();
  });

  it("standalone /planning (not embedded) hides the version selector and the status badge", () => {
    // ⚠ Ce test assertait aussi l'absence du score. P4-39 l'ayant retiré PARTOUT, cette
    // assertion serait devenue vraie quoi qu'il arrive : elle aurait continué de passer
    // même si la distinction standalone/embedded cassait, en donnant l'illusion de garder
    // une règle qu'elle ne gardait plus. Elle est déplacée dans le test ci-dessous, où
    // elle porte sur ce qui est réellement en jeu.
    renderToolbar(schedule("COMPLETED"), { embedded: false });
    expect(screen.queryByRole("combobox", { name: /version du planning/i })).not.toBeInTheDocument();
    expect(screen.queryByText("Terminé")).not.toBeInTheDocument();
    // View modes remain (consultation still switches gym/coach/team views).
    expect(screen.getByRole("button", { name: "Par coach" })).toBeInTheDocument();
  });

  it("n'affiche le score du solveur NULLE PART, embedded compris (P4-39)", () => {
    // Décision fondateur : « ça ne sert à rien pour le gestionnaire ». Le mode embedded est
    // celui qui l'affichait — c'est donc LUI qu'il faut interroger : le vérifier en
    // standalone ne prouverait rien, il n'y était déjà pas.
    renderToolbar(schedule("COMPLETED", { score: 9051 }), { embedded: true });

    expect(screen.queryByText(/score/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/9051/)).not.toBeInTheDocument();
    // …et le badge de statut, lui, reste : on retire le score, pas la ligne.
    expect(screen.getByText("Terminé")).toBeInTheDocument();
  });
});
