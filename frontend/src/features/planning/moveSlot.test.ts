import { HTTPError } from "ky";
import { afterEach, describe, expect, it, vi } from "vitest";

import { api } from "@/shared/api/client";

import { GenerationInProgressError, MoveRejectedError, moveSlot, placeSlot, SlotEditError, TargetLockedError } from "./api";

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

  // P2-30 PR B — éviction : le déplacement peut LIBÉRER une cible occupée.
  it("porte evictSlotId dans le corps et rend le bloc `evicted` d'un 200", async () => {
    const evicted = { slotId: "slot-y", teamId: "team-y", dayOfWeek: 2, startTime: "18:00", venueId: "v2", durationMinutes: 90 };
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: true, message: "Slot moved.", evicted }) } as never);

    const result = await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2", evictSlotId: "slot-y" });

    expect(mockPost.mock.calls[0][1]).toMatchObject({ json: { evictSlotId: "slot-y" } });
    expect(result.evicted).toEqual(evicted);
  });

  it("traduit un 422 target_locked en TargetLockedError PORTANT un message propre", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(422, { code: "target_locked", error: "Ce créneau est verrouillé — déverrouillez-le d'abord." })) } as never);
    const caught = (await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2", evictSlotId: "y" }).catch((e: unknown) => e)) as TargetLockedError;
    expect(caught).toBeInstanceOf(TargetLockedError);
    expect(caught.message).toMatch(/verrouill/i);
  });

  it("traduit un autre code 422 (evict_target_mismatch) en SlotEditError typée", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(422, { code: "evict_target_mismatch", error: "Le créneau à libérer ne correspond pas." })) } as never);
    const caught = (await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2", evictSlotId: "y" }).catch((e: unknown) => e)) as SlotEditError;
    expect(caught).toBeInstanceOf(SlotEditError);
    expect(caught.code).toBe("evict_target_mismatch");
  });
});

describe("placeSlot — placer une séance à la dérive sous le verdict moteur (P2-30 PR B)", () => {
  afterEach(() => mockPost.mockReset());

  it("appelle POST schedules/{id}/place-slot et rend {valid, slotId}", async () => {
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: true, slotId: "new-slot" }) } as never);

    const result = await placeSlot("sched-1", { teamId: "team-1", dayOfWeek: 3, startTime: "18:00", venueId: "v1" });

    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(mockPost.mock.calls[0][0]).toBe("schedules/sched-1/place-slot");
    expect(result.slotId).toBe("new-slot");
  });

  it("traduit un 422 avec violations en MoveRejectedError (parsing identique à moveSlot)", async () => {
    const violations = [{ rule: "coach_double_booking", message: "le coach a déjà les U13 ici." }];
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(422, { valid: false, violations })) } as never);

    const caught = (await placeSlot("sched-1", { teamId: "team-1", dayOfWeek: 3, startTime: "18:00", venueId: "v1" }).catch((e: unknown) => e)) as MoveRejectedError;
    expect(caught).toBeInstanceOf(MoveRejectedError);
    expect(caught.violations[0].message).toContain("U13");
  });

  it("traduit un 422 code (slot_unavailable) en SlotEditError typée", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(422, { code: "slot_unavailable", error: "Aucun créneau de gymnase n'est ouvert à cet horaire." })) } as never);
    const caught = (await placeSlot("sched-1", { teamId: "team-1", dayOfWeek: 3, startTime: "18:00", venueId: "v1" }).catch((e: unknown) => e)) as SlotEditError;
    expect(caught).toBeInstanceOf(SlotEditError);
    expect(caught.code).toBe("slot_unavailable");
  });

  it("traduit un 409 generation_in_progress en GenerationInProgressError", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(409, { code: "generation_in_progress" })) } as never);
    await expect(placeSlot("sched-1", { teamId: "team-1", dayOfWeek: 3, startTime: "18:00", venueId: "v1" })).rejects.toBeInstanceOf(GenerationInProgressError);
  });
});
