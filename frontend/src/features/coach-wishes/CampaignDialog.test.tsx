import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { CalendarEntry } from "@/features/cockpit/api";
import { setTodayOverride } from "@/shared/lib/clock";

import type { CoachWishCampaign } from "./campaignApi";

vi.mock("@/features/wizard/queries", () => ({
  useWizardTeams: () => ({ data: [{ id: "t1", name: "SM1", isActive: true }, { id: "t2", name: "U13", isActive: true }, { id: "t3", name: "U11", isActive: true }] }),
  useWizardTeamCoaches: () => ({ data: [{ id: "tc1", teamId: "t1", coachId: "c1", role: "MAIN" }, { id: "tc2", teamId: "t2", coachId: "c2", role: "MAIN" }] }),
  useUpdateCoach: () => ({ mutate: vi.fn() }),
}));

const createMut = vi.fn();
const updateMut = vi.fn();
const sendMut = vi.fn();
const remindMut = vi.fn();
const sendState: { isError: boolean; error: unknown } = { isError: false, error: null };
const remindState: { isError: boolean; error: unknown } = { isError: false, error: null };
vi.mock("./campaignQueries", () => ({
  useCreateCoachWishCampaign: () => ({ mutate: createMut, isPending: false, isError: false }),
  useUpdateCoachWishCampaign: () => ({ mutate: updateMut, isPending: false, isError: false }),
  useSendCampaignLinks: () => ({ mutate: sendMut, isPending: false, isError: sendState.isError, error: sendState.error }),
  useRemindCampaignSilent: () => ({ mutate: remindMut, isPending: false, isError: remindState.isError, error: remindState.error }),
}));

const copyMock = vi.fn().mockResolvedValue(true);
vi.mock("@/shared/lib/clipboard", () => ({ copyToClipboard: (t: string) => copyMock(t) }));

import { CampaignDialog } from "./CampaignDialog";

const entry: CalendarEntry = {
  id: "e1",
  kind: "period",
  title: "Vacances de février",
  startDate: "2026-02-16",
  endDate: "2026-03-01",
  isDisruptive: false,
  periodType: "holiday",
  schoolHolidayId: null,
  parentEntryId: null,
  status: "active",
  createdBy: null,
};
const season = { startDate: "2025-09-01", endDate: "2026-06-30" };

describe("CampaignDialog", () => {
  // P3-13/P3-15 (c) — la campagne ne propose que les semaines À VENIR. Ces fixtures sont
  // datées (fév. 2026) : sans horloge pilotable, elles deviennent du passé au fil du temps
  // et le test se met à échouer tout seul — il l'était déjà devenu. On ancre « aujourd'hui »
  // deux semaines avant la période plutôt que de repasser les dates en relatif.
  beforeEach(() => {
    setTodayOverride("2026-02-01");
    createMut.mockReset();
    updateMut.mockReset();
    sendMut.mockReset();
    remindMut.mockReset();
    copyMock.mockClear();
    sendState.isError = false;
    sendState.error = null;
    remindState.isError = false;
    remindState.error = null;
  });
  afterEach(() => setTodayOverride(null));

  it("crée une campagne avec les semaines et équipes choisies", async () => {
    render(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);

    // Seules les équipes AVEC coach sont proposées (t1/t2 ; t3 « U11 » sans coach est masquée).
    expect(screen.getByLabelText("SM1")).toBeInTheDocument();
    expect(screen.queryByLabelText("U11")).not.toBeInTheDocument();

    await userEvent.click(screen.getByLabelText("SM1"));
    await userEvent.click(screen.getByRole("button", { name: /Créer la collecte/ }));

    expect(createMut).toHaveBeenCalledTimes(1);
    const body = createMut.mock.calls[0][0];
    expect(body.calendarEntryId).toBe("e1");
    expect(body.teamIds).toEqual(["t1"]);
    expect(body.weeks.length).toBeGreaterThan(0);
    expect(body.deadline).toBe("2026-02-16");
  });

  // ── P3-15 (c) : on ne sollicite un coach que pour ce qu'il RESTE (retour 2026-07-31) ──

  // « Les semaines passées et la semaine en cours sont proposées ET cochées par défaut » :
  // le gestionnaire envoyait à ses coachs un lien pour dire leurs souhaits sur du révolu.
  // ⚠ RÉVOLU, pas « entamé » (revue #344) : une vacance qui démarre un samedi n'aurait
  // plus pu faire l'objet d'aucune collecte dès le lundi suivant, pour des séances
  // pourtant toutes à venir — et rien d'autre dans l'app ne crée une campagne.
  it("n'offre ni ne coche une semaine révolue, garde celle qui est entamée", () => {
    // Période du 16/02 au 01/03 = deux semaines (16 et 23). « Aujourd'hui » = lundi 23 :
    // la semaine du 16 est finie, celle du 23 court encore.
    setTodayOverride("2026-02-23");
    render(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);

    expect(screen.queryByLabelText(/Semaine du 16/)).toBeNull();
    const current = screen.getByLabelText(/Semaine du 23/);
    expect(current).toBeInTheDocument();
    expect(current).toBeChecked();
  });

  // Le cas qui rendait la collecte IMPOSSIBLE avec le premier critère : la période
  // n'a plus qu'une semaine, entamée, mais ses jours utiles sont devant.
  it("laisse créer une collecte sur une semaine entamée aux jours encore à venir", () => {
    setTodayOverride("2026-02-25"); // mercredi de la dernière semaine, qui finit le 01/03
    render(<CampaignDialog entry={entry} season={season} existing={null} onClose={vi.fn()} />);

    expect(screen.queryByText(/Aucune semaine disponible/)).toBeNull();
    expect(screen.getByLabelText(/Semaine du 23/)).toBeChecked();
  });

  // ATTEINDRE ≠ CHOISIR : une campagne EXISTANTE peut porter une semaine devenue révolue.
  // La masquer laisserait l'état porter un lundi que l'écran ne montre pas et que
  // l'enregistrement renverrait — un état invisible est un état faux. Le marqueur vit
  // DANS le nom accessible, sinon un lecteur d'écran ne l'entend jamais (revue #344).
  it("garde visible et marquée une semaine déjà retenue devenue révolue", () => {
    setTodayOverride("2026-02-25");
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2026-03-01",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    const past = screen.getByLabelText("Semaine du 16/02/2026 (révolue)");
    expect(past).toBeInTheDocument();
    expect(past).toBeChecked();
  });

  it("copie le lien personnel d'un coach", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [{ coachId: "c1", firstName: "Maxime", lastName: "Durand", email: null, token: "a".repeat(64), respondedAt: null, sentAt: null }],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    await userEvent.click(screen.getByRole("button", { name: /Copier le lien/ }));
    expect(copyMock).toHaveBeenCalledWith(`${window.location.origin}/doleances/${"a".repeat(64)}`);
  });

  it("envoie les liens aux coachs à email pas encore servis (D2)", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 2,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [
        { coachId: "c1", firstName: "Maxime", lastName: "Durand", email: "max@test.fr", token: "a".repeat(64), respondedAt: null, sentAt: null },
        { coachId: "c2", firstName: "Mara", lastName: "Petit", email: null, token: "b".repeat(64), respondedAt: null, sentAt: null },
      ],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    // Le coach sans email porte le badge et pas de bouton d'envoi individuel.
    expect(screen.getByText("pas d'email")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /Envoyer les liens par email/ }));
    expect(sendMut).toHaveBeenCalledTimes(1);
    expect(sendMut.mock.calls[0][0]).toEqual({ id: "camp1" });
  });

  it("filtre la liste des coachs par équipe et par statut (D1-D5)", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1", "t2"],
      totalCoachCount: 2,
      respondedCoachCount: 1,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [
        { coachId: "c1", firstName: "Maxime", lastName: "SM1", email: "max@test.fr", token: "a".repeat(64), respondedAt: "2026-02-01T10:00:00Z", sentAt: null },
        { coachId: "c2", firstName: "Mara", lastName: "U13", email: null, token: "b".repeat(64), respondedAt: null, sentAt: null },
      ],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    // Sans filtre : les deux coachs.
    expect(screen.getByText("Maxime SM1")).toBeInTheDocument();
    expect(screen.getByText("Mara U13")).toBeInTheDocument();

    // Filtre équipe SM1 → Mara (U13) disparaît.
    await userEvent.click(screen.getByRole("button", { name: "SM1", pressed: false }));
    expect(screen.getByText("Maxime SM1")).toBeInTheDocument();
    expect(screen.queryByText("Mara U13")).not.toBeInTheDocument();

    // On enlève le filtre équipe, on filtre par statut « pas d'email » → seul Mara.
    await userEvent.click(screen.getByRole("button", { name: "SM1", pressed: true }));
    await userEvent.click(screen.getByRole("button", { name: "Pas d'email" }));
    expect(screen.getByText("Mara U13")).toBeInTheDocument();
    expect(screen.queryByText("Maxime SM1")).not.toBeInTheDocument();
  });

  it("classe un répondant SANS email en « Répondu », pas « Pas d'email » (WhatsApp)", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1", "t2"],
      totalCoachCount: 2,
      respondedCoachCount: 1,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [
        { coachId: "c1", firstName: "Maxime", lastName: "SM1", email: "max@test.fr", token: "a".repeat(64), respondedAt: null, sentAt: null },
        // Répond via WhatsApp, aucun email en fiche : doit rester « Répondu ».
        { coachId: "c2", firstName: "Wanda", lastName: "U13", email: null, token: "b".repeat(64), respondedAt: "2026-02-01T10:00:00Z", sentAt: null },
      ],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    await userEvent.click(screen.getByRole("button", { name: "Répondu" }));
    expect(screen.getByText("Wanda U13")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Répondu", pressed: true }));
    await userEvent.click(screen.getByRole("button", { name: "Pas d'email" }));
    expect(screen.queryByText("Wanda U13")).not.toBeInTheDocument();
  });

  it("affiche « saison archivée » sur un 409, pas « déjà relancé »", () => {
    remindState.isError = true;
    remindState.error = new HTTPError(new Response(null, { status: 409 }), new Request("http://x/api/coach_wish_campaigns/camp1/remind"), {} as never);
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [{ coachId: "c1", firstName: "Maxime", lastName: "SM1", email: "max@test.fr", token: "a".repeat(64), respondedAt: null, sentAt: "2026-01-01T08:00:00Z" }],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);
    expect(screen.getByText(/saison est archivée/)).toBeInTheDocument();
  });

  it("affiche une erreur si la relance échoue (feedback, pas muet)", () => {
    remindState.isError = true;
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [{ coachId: "c1", firstName: "Maxime", lastName: "SM1", email: "max@test.fr", token: "a".repeat(64), respondedAt: null, sentAt: "2026-01-01T08:00:00Z" }],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    expect(screen.getByText(/Relance impossible/)).toBeInTheDocument();
  });

  it("bloque la relance si déjà relancé aujourd'hui (D3)", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: new Date().toISOString(),
      coaches: [{ coachId: "c1", firstName: "Maxime", lastName: "Durand", email: "max@test.fr", token: "a".repeat(64), respondedAt: null, sentAt: "2026-01-01T08:00:00Z" }],
    };
    render(<CampaignDialog entry={entry} season={season} existing={existing} onClose={vi.fn()} />);

    expect(screen.getByRole("button", { name: /Relancer les silencieux/ })).toBeDisabled();
  });
});
