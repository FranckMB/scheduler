import { QueryClient, QueryClientProvider, useMutation, useQuery } from "@tanstack/react-query";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createRef, useImperativeHandle, useMemo, type ReactNode, type Ref } from "react";

import { registerLongAction, unregisterLongAction } from "@/shared/lib/longActionAbort";
import { useToastStore } from "@/shared/stores/toastStore";

import { ActionVeil } from "./ActionVeil";

// Lot C PR-2 — le voile bloquant. jsdom n'a AUCUN moteur de mise en page ni hit-testing : on
// atteste ici les ATTRIBUTS (inert, overlay, rôles, focus), avec des horloges FACTICES pour les
// seuils 250 ms / 2,6 s / 10 s. Le double-clic réel et le contraste vivent en Playwright.

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

function MutationProbe({ meta, register, ref }: { meta?: { veil?: false | "long" }; register?: boolean; ref?: Ref<Ctl> }) {
  const deferred = useMemo(() => makeDeferred(), []);
  const m = useMutation({
    mutationFn: () =>
      register
        ? new Promise<void>((_res, reject) => {
            const controller = registerLongAction();
            controller.signal.addEventListener("abort", () => {
              unregisterLongAction(controller);
              reject(new Error("aborted"));
            });
          })
        : deferred.promise,
    meta,
  });
  useImperativeHandle(ref, () => ({ start: () => m.mutate(), resolve: deferred.resolve }));
  return <input data-testid="origin" aria-label="origine" />;
}

function QueryProbe({ meta, queryKey = ["probe"] }: { meta?: { veil?: false }; queryKey?: string[] }) {
  useQuery({ queryKey, queryFn: () => new Promise<{ x: number }>(() => {}), meta, retry: false });
  return null;
}

function renderVeil(ui: ReactNode, qc: QueryClient) {
  return render(
    <QueryClientProvider client={qc}>
      <ActionVeil>{ui}</ActionVeil>
    </QueryClientProvider>,
  );
}

const newClient = () => new QueryClient({ defaultOptions: { queries: { retry: false } } });
const content = () => screen.getByTestId("veil-content");
const overlay = () => screen.queryByTestId("action-veil");

// advanceTimersByTimeAsync flushe les microtasks ENTRE les timers — indispensable pour que la
// propagation asynchrone de react-query (mutation pending → useIsMutating) se règle sous horloge
// factice.
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
  useToastStore.setState({ toasts: [] });
});
afterEach(() => {
  vi.runOnlyPendingTimers();
  vi.useRealTimers();
  useToastStore.setState({ toasts: [] });
});

describe("ActionVeil — blocage immédiat, voile différé", () => {
  it("mutation NON exemptée : inert IMMÉDIAT, mais voile INVISIBLE avant 250 ms puis visible après", async () => {
    const ctl = createRef<Ctl>();
    const qc = newClient();
    renderVeil(<MutationProbe ref={ctl} />, qc);

    act(() => ctl.current!.start());
    await flush();

    // Phase 0 : les clics sont bloqués tout de suite (inert + overlay), rien ne clignote encore.
    expect(content()).toHaveAttribute("inert");
    expect(overlay()).toBeInTheDocument();
    expect(screen.queryByRole("status")).toBeNull();

    // Phase 1 : à 250 ms le panneau apparaît.
    await advance(250);
    expect(screen.getByRole("status")).toBeInTheDocument();
  });

  it("mutation EXEMPTÉE (veil:false) : jamais d'inert ni d'overlay", async () => {
    const ctl = createRef<Ctl>();
    const qc = newClient();
    renderVeil(<MutationProbe ref={ctl} meta={{ veil: false }} />, qc);

    act(() => ctl.current!.start());
    await flush();
    await advance(300);

    expect(content()).not.toHaveAttribute("inert");
    expect(overlay()).toBeNull();
  });

  it("un réordonnancement d'équipe (mutation sans meta) voile bien l'écran (réparation b)", async () => {
    // Le rail de réordonnancement du wizard (useReorderTeams) ne porte AUCUN meta : il tombe donc
    // sous le voile global par défaut. Ceinture (garde locale reorderBusy) ET bretelles (ce voile).
    const ctl = createRef<Ctl>();
    const qc = newClient();
    renderVeil(<MutationProbe ref={ctl} />, qc);

    act(() => ctl.current!.start());
    await flush();

    expect(content()).toHaveAttribute("inert");
  });
});

describe("ActionVeil — premier chargement vs refetch d'arrière-plan (Changement de page)", () => {
  it("premier chargement SANS cache → voile", async () => {
    const qc = newClient();
    renderVeil(<QueryProbe />, qc);
    await flush();

    expect(content()).toHaveAttribute("inert");
    await advance(250);
    expect(screen.getByRole("status")).toBeInTheDocument();
  });

  it("refetch d'arrière-plan (données EN CACHE) → JAMAIS de voile", async () => {
    const qc = newClient();
    qc.setQueryData(["probe"], { x: 1 });
    renderVeil(<QueryProbe />, qc);
    await flush();
    await advance(300);

    // La query refetch (queryFn ne résout jamais) mais data est présente → pas un premier chargement.
    expect(content()).not.toHaveAttribute("inert");
    expect(overlay()).toBeNull();
  });
});

describe("ActionVeil — échappatoires : relâche au chrono (courts) vs bouton d'abandon (long)", () => {
  it("contexte court : à 10 s, on PRÉVIENT (toast) ET on RELÂCHE (inert retiré)", async () => {
    const ctl = createRef<Ctl>();
    const qc = newClient();
    renderVeil(<MutationProbe ref={ctl} />, qc);

    act(() => ctl.current!.start());
    await flush();
    await advance(250);
    expect(content()).toHaveAttribute("inert");

    await advance(10_000);

    expect(content()).not.toHaveAttribute("inert");
    expect(overlay()).toBeNull();
    expect(useToastStore.getState().toasts.some((t) => /plus que prévu/i.test(t.message))).toBe(true);
  });

  it("contexte LONG : à 10 s+, l'inert reste INTACT (jamais de relâche au chrono) et pas d'avertissement", async () => {
    const ctl = createRef<Ctl>();
    const qc = newClient();
    renderVeil(<MutationProbe ref={ctl} meta={{ veil: "long" }} register />, qc);

    act(() => ctl.current!.start());
    await flush();
    await advance(250);
    await advance(15_000);

    expect(content()).toHaveAttribute("inert");
    expect(useToastStore.getState().toasts.some((t) => /plus que prévu/i.test(t.message))).toBe(false);
  });

  it("contexte LONG : le bouton « Abandonner ce déplacement » apparaît dès le voile (250 ms) et prend le focus", async () => {
    const ctl = createRef<Ctl>();
    const qc = newClient();
    renderVeil(<MutationProbe ref={ctl} meta={{ veil: "long" }} register />, qc);

    act(() => ctl.current!.start());
    await flush();

    expect(screen.queryByRole("button", { name: /abandonner ce déplacement/i })).toBeNull();
    await advance(250);
    const abandon = screen.getByRole("button", { name: /abandonner ce déplacement/i });
    expect(abandon).toBeInTheDocument();
    // C'est un dialog (bouton présent), pas un simple status.
    expect(screen.getByRole("dialog")).toBeInTheDocument();
    expect(document.activeElement).toBe(abandon);
  });
});

describe("ActionVeil — focus", () => {
  it("abandon : le focus revient à l'élément qui l'avait avant le voile", async () => {
    const ctl = createRef<Ctl>();
    const qc = newClient();
    renderVeil(<MutationProbe ref={ctl} meta={{ veil: "long" }} register />, qc);

    const origin = screen.getByTestId("origin");
    origin.focus();
    expect(document.activeElement).toBe(origin);

    act(() => ctl.current!.start());
    await flush();
    await advance(250);
    const abandon = screen.getByRole("button", { name: /abandonner ce déplacement/i });
    expect(document.activeElement).toBe(abandon);

    fireEvent.click(abandon);
    await flush();

    expect(document.activeElement).toBe(origin);
  });

  it("settle : le voile tombe et le focus est restauré (contexte court)", async () => {
    const ctl = createRef<Ctl>();
    const qc = newClient();
    renderVeil(<MutationProbe ref={ctl} />, qc);

    const origin = screen.getByTestId("origin");
    origin.focus();

    act(() => ctl.current!.start());
    await flush();
    await advance(250);
    expect(content()).toHaveAttribute("inert");

    act(() => ctl.current!.resolve());
    await flush();

    expect(overlay()).toBeNull();
    expect(content()).not.toHaveAttribute("inert");
    expect(document.activeElement).toBe(origin);
  });
});
