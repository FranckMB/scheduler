import { HTTPError } from "ky";
import { afterEach, describe, expect, it, vi } from "vitest";

import { api } from "@/shared/api/client";

import { GenerationInProgressError, MoveRejectedError, moveSlot } from "./api";

// F2b — le déplacement passe désormais par POST /move (verdict moteur), plus par
// manual-edit/one-time (chevauchements bruts). Ce test garde le CHEMIN d'appel et la
// traduction des refus en erreurs typées que l'UI sait afficher.
vi.mock("@/shared/api/client", () => ({ api: { post: vi.fn(), get: vi.fn() } }));

const mockPost = vi.mocked(api.post);

function httpError(status: number, data: unknown): HTTPError {
  const response = new Response(JSON.stringify(data), { status });
  const request = new Request("http://localhost/api/schedule-slots/s1/move");
  const error = new HTTPError(response, request, {} as never);
  // ky 2.x expose le corps d'erreur parsé sur error.data (re-lire response throw).
  (error as unknown as { data: unknown }).data = data;
  return error;
}

describe("moveSlot — sous le verdict moteur (F2b)", () => {
  afterEach(() => mockPost.mockReset());

  it("appelle le rail /move (jamais manual-edit/one-time)", async () => {
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: true }) } as never);
    await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2" });

    expect(mockPost).toHaveBeenCalledTimes(1);
    const [path] = mockPost.mock.calls[0];
    expect(path).toBe("schedule-slots/s1/move");
    expect(path).not.toContain("one-time");
  });

  it("traduit un 422 en MoveRejectedError PORTANT les règles violées nommées", async () => {
    const violations = [{ rule: "coach_double_booking", message: "le coach Dupont a déjà les U15 à 20h." }];
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(422, { valid: false, violations })) } as never);

    await expect(moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2" })).rejects.toBeInstanceOf(MoveRejectedError);
    const caught = await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2" }).catch((e: unknown) => e);
    expect(caught).toBeInstanceOf(MoveRejectedError);
    expect((caught as MoveRejectedError).violations[0].message).toContain("Dupont");
  });

  it("porte les ids du verdict (surlignage du conflit) — conflictingTeamId compris", async () => {
    const violations = [
      {
        rule: "coach_double_booking",
        message: "le coach Dupont a déjà les U15 à 20h.",
        coachId: "coach-1",
        dayOfWeek: 4,
        startTime: "20:00",
        conflictingTeamId: "team-u15",
        teamId: null,
        venueId: null,
      },
    ];
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(422, { valid: false, violations })) } as never);

    const caught = (await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2" }).catch((e: unknown) => e)) as MoveRejectedError;
    expect(caught).toBeInstanceOf(MoveRejectedError);
    expect(caught.violations[0].conflictingTeamId).toBe("team-u15");
    expect(caught.violations[0].coachId).toBe("coach-1");
    expect(caught.violations[0].dayOfWeek).toBe(4);
    expect(caught.violations[0].teamId).toBeNull();
  });

  it("traduit un 409 generation_in_progress en GenerationInProgressError", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(409, { code: "generation_in_progress" })) } as never);
    await expect(moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2" })).rejects.toBeInstanceOf(GenerationInProgressError);
  });
});
