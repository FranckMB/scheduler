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

// P2-36 — le dialogue s'OUVRE toujours et NOMME son état, au lieu de basculer en bloc sans un
// mot. Quatre causes distinctes ; ici les trois qui ouvrent un dialogue : chargement, choix des
// semaines (couvert plus haut), bloc déjà généré.
describe("WeekPickerDialog — états nommés (P2-36)", () => {
  const block = { versionCount: 2, validated: false, generationInFlight: false, deleting: false, deleteFailed: false, onDeleteVersions: vi.fn() };

  it("état « chargement » : le dit, n'affiche PAS de cases à cocher, garde « d'un bloc »", () => {
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} state="loading" onPickWeeks={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} />);

    expect(screen.getByText(/On vérifie l'état de/)).toBeInTheDocument();
    // Pas de coches tant qu'on ne sait pas (le repli « ne jamais cocher sans savoir » demeure)…
    expect(screen.queryByRole("checkbox")).not.toBeInTheDocument();
    // …ni de bouton « Créer », mais le chemin « d'un bloc » reste offert.
    expect(screen.queryByRole("button", { name: /créer/i })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /d'un bloc/i })).toBeInTheDocument();
  });

  it("état « bloc » : NOMME le fait (N versions), garde « Continuer d'un bloc », propose la découpe", () => {
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} state="block" block={block} onPickWeeks={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} />);

    expect(screen.getByText(/déjà été adaptée d'un bloc — 2 versions/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Continuer d'un bloc/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Supprimer les versions et découper en semaines/i })).toBeInTheDocument();
    // « Bloc » n'offre PAS de cases : on repart de zéro, on ne coche pas sur des versions existantes.
    expect(screen.queryByRole("checkbox")).not.toBeInTheDocument();
  });

  it("« Continuer d'un bloc » conserve le comportement (appelle onAdaptWhole)", async () => {
    const user = userEvent.setup();
    const onAdaptWhole = vi.fn();
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} state="block" block={block} onPickWeeks={vi.fn()} onAdaptWhole={onAdaptWhole} onClose={vi.fn()} />);

    await user.click(screen.getByRole("button", { name: /Continuer d'un bloc/i }));
    expect(onAdaptWhole).toHaveBeenCalled();
  });

  it("la confirmation NOMME la portée : nombre de versions supprimées + réglages repartant de la saison", async () => {
    const user = userEvent.setup();
    const onDeleteVersions = vi.fn();
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} state="block" block={{ ...block, onDeleteVersions }} onPickWeeks={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} />);

    await user.click(screen.getByRole("button", { name: /Supprimer les versions et découper en semaines/i }));
    // La confirmation nomme la portée avant d'agir.
    expect(screen.getByText(/supprime 2 versions déjà générées/)).toBeInTheDocument();
    expect(screen.getByText(/repartiront de la saison/)).toBeInTheDocument();
    expect(onDeleteVersions).not.toHaveBeenCalled(); // rien tant que non confirmé
    await user.click(screen.getByRole("button", { name: "Supprimer et découper" }));
    expect(onDeleteVersions).toHaveBeenCalled();
  });

  it("bloc VALIDÉ : PAS de bouton de découpe, la raison est affichée et renvoie aux gestes existants", () => {
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} state="block" block={{ ...block, validated: true }} onPickWeeks={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} />);

    expect(screen.queryByRole("button", { name: /Supprimer les versions et découper/i })).not.toBeInTheDocument();
    expect(screen.getByText(/validé.*rouvrez-le puis supprimez-le/i)).toBeInTheDocument();
    // Le comportement de repli reste offert.
    expect(screen.getByRole("button", { name: /Continuer d'un bloc/i })).toBeInTheDocument();
  });

  it("génération en vol : le bouton de découpe est DÉSACTIVÉ avec sa raison", () => {
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} state="block" block={{ ...block, generationInFlight: true }} onPickWeeks={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} />);

    expect(screen.getByRole("button", { name: /Supprimer les versions et découper/i })).toBeDisabled();
    expect(screen.getByText(/génération est en cours/i)).toBeInTheDocument();
  });
});

// P2-40 — une fermeture qui chevauche des vacances : les semaines gouvernées par les vacances sont
// EXCLUES (pas grisées), une ligne d'info le dit, et le chemin « d'un bloc » DISPARAÎT (un plan de
// bloc gouvernerait la fenêtre des vacances). 100 % sous vacances → info seule ; sur le chemin
// pending, un bouton « Consigner l'indisponibilité » crée le FAIT sans plan ni navigation.
describe("WeekPickerDialog — chevauchement vacances (P2-40)", () => {
  const offered = [
    { startDate: "2026-09-07", endDate: "2026-09-13", monday: "2026-09-07" },
    { startDate: "2026-09-14", endDate: "2026-09-20", monday: "2026-09-14" },
  ];
  const excludedRanges = [{ startDate: "2026-08-17", endDate: "2026-09-06", labels: ["Vacances d'été"] }];

  it("affiche la ligne d'info, propose les semaines hors vacances, et RETIRE le chemin d'un bloc", () => {
    render(
      <WeekPickerDialog
        title="Armand indisponible"
        startDate="2026-08-17"
        endDate="2026-10-01"
        weeks={offered}
        excludedRanges={excludedRanges}
        busy={false}
        state="holiday"
        onPickWeeks={vi.fn()}
        onAdaptWhole={vi.fn()}
        onClose={vi.fn()}
      />,
    );

    expect(screen.getByText(/couvertes par Vacances d'été/)).toBeInTheDocument();
    expect(screen.getByText(/le rappel vous attend/i)).toBeInTheDocument();
    expect(screen.getAllByRole("checkbox")).toHaveLength(2);
    // Le chemin « d'un bloc » a disparu (les deux libellés possibles).
    expect(screen.queryByRole("button", { name: /d'un bloc/i })).not.toBeInTheDocument();
  });

  it("100 % sous vacances (aucune semaine offerte) → info seule + « Consigner l'indisponibilité » (chemin pending)", async () => {
    const user = userEvent.setup();
    const onRecordOnly = vi.fn();
    render(
      <WeekPickerDialog
        title="Armand indisponible"
        startDate="2026-08-03"
        endDate="2026-08-28"
        weeks={[]}
        excludedRanges={excludedRanges}
        busy={false}
        state="holiday"
        onPickWeeks={vi.fn()}
        onAdaptWhole={vi.fn()}
        onClose={vi.fn()}
        onRecordOnly={onRecordOnly}
      />,
    );

    expect(screen.queryByRole("checkbox")).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /créer/i })).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /consigner l'indisponibilité/i }));
    expect(onRecordOnly).toHaveBeenCalled();
  });

  it("entrée déjà en base (pas de onRecordOnly) : info seule, aucun bouton d'action", () => {
    render(
      <WeekPickerDialog
        title="Armand indisponible"
        startDate="2026-08-03"
        endDate="2026-08-28"
        weeks={[]}
        excludedRanges={excludedRanges}
        busy={false}
        state="holiday"
        onPickWeeks={vi.fn()}
        onAdaptWhole={vi.fn()}
        onClose={vi.fn()}
      />,
    );

    expect(screen.queryByRole("button", { name: /consigner l'indisponibilité/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /d'un bloc/i })).not.toBeInTheDocument();
    expect(screen.getByText(/couvertes par Vacances d'été/)).toBeInTheDocument();
  });
});
