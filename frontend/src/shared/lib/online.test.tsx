import { onlineManager } from "@tanstack/react-query";
import { act, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { seedOnlineFromNavigator, useOnline } from "./online";

// P5-14 — la SOURCE DE VÉRITÉ unique de l'état réseau. `useOnline` s'abonne à
// l'`onlineManager` de react-query (le même que celui qui reprend les mutations en pause), et
// `seedOnlineFromNavigator` comble son défaut « optimiste » (il naît `#online = true` et ne
// bascule que sur les événements window — au boot hors ligne il MENT). On mute la PROD (le vrai
// singleton `onlineManager`), jamais un mock.

function OnlineProbe() {
  const online = useOnline();
  return <span data-testid="state">{online ? "online" : "offline"}</span>;
}
const state = () => screen.getByTestId("state").textContent;

beforeEach(() => {
  onlineManager.setOnline(true);
});
afterEach(() => {
  onlineManager.setOnline(true); // singleton global : ne pas polluer les autres suites
  vi.restoreAllMocks();
});

describe("useOnline — miroir réactif de l'onlineManager", () => {
  it("reflète l'état courant au rendu (en ligne)", () => {
    render(<OnlineProbe />);
    expect(state()).toBe("online");
  });

  it("bascule quand l'onlineManager passe hors ligne, puis revient", () => {
    render(<OnlineProbe />);
    act(() => onlineManager.setOnline(false));
    expect(state()).toBe("offline");
    act(() => onlineManager.setOnline(true));
    expect(state()).toBe("online");
  });
});

describe("seedOnlineFromNavigator — comble le défaut optimiste au boot", () => {
  it("navigator.onLine=false au boot → l'onlineManager bascule hors ligne (sinon il resterait « en ligne »)", () => {
    const onLine = vi.spyOn(navigator, "onLine", "get").mockReturnValue(false);
    // Le défaut : sans seed, l'onlineManager naît optimiste malgré navigator.onLine.
    expect(onlineManager.isOnline()).toBe(true);
    seedOnlineFromNavigator();
    expect(onlineManager.isOnline()).toBe(false);
    onLine.mockRestore();
  });

  it("navigator.onLine=true au boot → aucun changement (reste en ligne)", () => {
    const onLine = vi.spyOn(navigator, "onLine", "get").mockReturnValue(true);
    seedOnlineFromNavigator();
    expect(onlineManager.isOnline()).toBe(true);
    onLine.mockRestore();
  });
});
