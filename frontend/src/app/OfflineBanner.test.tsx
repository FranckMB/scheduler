import { onlineManager, QueryClient, QueryClientProvider, useMutation } from "@tanstack/react-query";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createRef, useImperativeHandle, useMemo, type ReactNode, type Ref } from "react";

import { OfflineBanner } from "./OfflineBanner";

// P5-14 — le bandeau hors-ligne. On mute la PROD (vrai `onlineManager`, vrai `QueryClient`), jamais
// un mock. Le compteur des gestes en attente = mutations réellement EN PAUSE (`networkMode: "online"`
// par défaut → hors ligne, une mutation ne démarre pas et part `isPaused: true`). jsdom n'a aucun
// moteur de mise en page : on atteste les TEXTES, les rôles et l'ABSENCE/présence du bouton, avec
// des horloges factices pour l'effacement à 5 s. Le décalage de layout et le contraste vivent en
// Playwright (hors de ce fichier).

interface Ctl {
  start: () => void;
  resolve: () => void;
}

function makeDeferred(): { promise: Promise<void>; resolve: () => void } {
  let resolve!: () => void;
  const promise = new Promise<void>((res) => {
    resolve = res;
  });
  return { promise, resolve };
}

// Une sonde = une mutation. `networkMode` par défaut ("online") → hors ligne, elle se met en pause.
function MutationProbe({ ref }: { ref?: Ref<Ctl> }) {
  const deferred = useMemo(() => makeDeferred(), []); // STABLE : sinon `resolve` viserait une autre promesse que celle attendue
  const m = useMutation({ mutationFn: () => deferred.promise });
  useImperativeHandle(ref, () => ({ start: () => m.mutate(), resolve: deferred.resolve }));
  return null;
}

function renderBanner(ui: ReactNode, qc: QueryClient) {
  return render(
    <QueryClientProvider client={qc}>
      <OfflineBanner />
      {ui}
    </QueryClientProvider>,
  );
}

const newClient = () => new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
const banner = () => screen.queryByTestId("offline-banner");
const sendButton = () => screen.queryByRole("button", { name: /envoyer maintenant/i });

async function flush() {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(0);
  });
}
async function advance(ms: number) {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(ms);
  });
}

beforeEach(() => {
  vi.useFakeTimers();
  onlineManager.setOnline(true);
});
afterEach(() => {
  vi.runOnlyPendingTimers();
  vi.useRealTimers();
  onlineManager.setOnline(true); // singleton global — ne pas laisser un test hors ligne polluer un autre
});

describe("OfflineBanner — état 1 : hors ligne, aucun geste en attente", () => {
  it("en ligne sans rien en attente → AUCUN bandeau (cas nominal, y compris page publique)", async () => {
    renderBanner(null, newClient());
    await flush();
    expect(banner()).toBeNull();
  });

  it("hors ligne, 0 en attente → « Vous êtes hors ligne… », SANS bouton", async () => {
    onlineManager.setOnline(false);
    renderBanner(null, newClient());
    await flush();

    // La MÊME phrase vit aussi dans la région sr-only (annonce de transition) : on scope au bandeau visible.
    expect(within(screen.getByTestId("offline-banner")).getByText(/vous êtes hors ligne\. vos données restent consultables\./i)).toBeInTheDocument();
    expect(sendButton()).toBeNull();
  });
});

describe("OfflineBanner — état 2 : hors ligne, N gestes en attente (compteur exact, JAMAIS de bouton)", () => {
  it("2 mutations en pause → « 2 modifications en attente… », compteur exact, PAS de bouton (no-op prouvé hors ligne)", async () => {
    onlineManager.setOnline(false);
    const a = createRef<Ctl>();
    const b = createRef<Ctl>();
    const qc = newClient();
    renderBanner(
      <>
        <MutationProbe ref={a} />
        <MutationProbe ref={b} />
      </>,
      qc,
    );

    act(() => {
      a.current!.start();
      b.current!.start();
    });
    await flush();

    expect(screen.getByText(/2 modifications en attente/i)).toBeInTheDocument();
    expect(screen.getByText(/gardez cet onglet ouvert/i)).toBeInTheDocument();
    // ⚠ Le bouton « Envoyer maintenant » serait un NO-OP hors ligne (queryClient.js:206). Il n'existe pas ici.
    expect(sendButton()).toBeNull();
  });
});

describe("OfflineBanner — état 4 : retour en ligne → confirmation, puis effacement à 5 s", () => {
  it("hors ligne avec 1 geste garé, réseau revient → il repart (auto-reprise) → confirmation → efface à 5 s", async () => {
    onlineManager.setOnline(false);
    const a = createRef<Ctl>();
    const qc = newClient();
    renderBanner(<MutationProbe ref={a} />, qc);

    act(() => a.current!.start());
    await flush();
    expect(screen.getByText(/1 modification en attente/i)).toBeInTheDocument();

    // Réseau revient : la reprise automatique (onlineManager → resumePausedMutations) relance la
    // mutation garée ; on la résout → succès.
    act(() => onlineManager.setOnline(true));
    await flush();
    act(() => a.current!.resolve());
    await flush();

    // Confirmation dans le bandeau visible (la même phrase est aussi annoncée en sr-only) :
    expect(within(screen.getByTestId("offline-banner")).getByText(/de retour en ligne\. vos modifications sont parties\./i)).toBeInTheDocument();
    expect(sendButton()).toBeNull(); // plus rien en pause → pas de filet

    // La confirmation s'efface seule après 5 s.
    await advance(5000);
    expect(banner()).toBeNull();
  });
});

describe("OfflineBanner — état 3 : le filet « Envoyer maintenant » n'existe QU'en ligne s'il RESTE des pauses", () => {
  it("en ligne avec une pause qui a échappé à l'auto-reprise → bouton présent ; clic → resumePausedMutations", async () => {
    onlineManager.setOnline(false);
    const a = createRef<Ctl>();
    const qc = newClient();
    renderBanner(<MutationProbe ref={a} />, qc);

    act(() => a.current!.start());
    await flush();

    // On coupe l'abonnement auto du client à l'onlineManager (unmount) AVANT de repasser en ligne :
    // c'est la SIMULATION du cas « l'auto-reprise n'a pas tiré » (race/anomalie) — la pause SURVIT au
    // retour du réseau, et c'est exactement là que le filet manuel doit apparaître.
    act(() => qc.unmount());
    act(() => onlineManager.setOnline(true));
    await flush();

    const resume = vi.spyOn(qc, "resumePausedMutations");
    const btn = sendButton();
    expect(btn).not.toBeNull();
    fireEvent.click(btn!);
    expect(resume).toHaveBeenCalled();
    resume.mockRestore();
  });
});

describe("OfflineBanner — annonce lecteur d'écran (transitions seulement, jamais le compteur)", () => {
  it("une région sr-only role=status annonce le passage hors ligne ; le compteur qui monte ne la change pas", async () => {
    onlineManager.setOnline(false);
    const a = createRef<Ctl>();
    const b = createRef<Ctl>();
    const qc = newClient();
    renderBanner(
      <>
        <MutationProbe ref={a} />
        <MutationProbe ref={b} />
      </>,
      qc,
    );
    await flush();

    const status = screen.getByRole("status");
    expect(status).toHaveTextContent(/hors ligne/i);
    // Le compteur incrémente → la phrase de la région live NE change PAS (patron AUD-FRT-23/24).
    const announced = status.textContent;
    act(() => {
      a.current!.start();
      b.current!.start();
    });
    await flush();
    expect(status.textContent).toBe(announced);
    // …alors que le bandeau VISIBLE, lui, a bien le compteur.
    expect(screen.getByText(/2 modifications en attente/i)).toBeInTheDocument();
  });
});
