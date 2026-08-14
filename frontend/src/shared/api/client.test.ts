import { afterEach, describe, expect, it, vi } from "vitest";

import { api } from "./client";

/**
 * P5-11 — chaque requête sortante porte un X-Request-Id UNIQUE (crypto.randomUUID),
 * pour corréler front→backend→bus→engine. On exerce les VRAIS hooks de `api`
 * (pas un mock) : `api.extend` HÉRITE des hooks de production ; on ne fournit qu'un
 * `baseUrl` absolu, car le `Request` de Node (undici) ne résout pas une
 * URL relative comme le fait le navigateur. Le header est lu sur la Request telle
 * que ky l'a réellement construite, via un `fetch` intercepté.
 */
describe("api client — X-Request-Id", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("pose un X-Request-Id distinct à chaque requête", async () => {
    const seen: string[] = [];
    const fetchMock = vi.fn(async (input: Request | string | URL, init?: RequestInit) => {
      const request = input instanceof Request ? input : new Request(input, init);
      seen.push(request.headers.get("X-Request-Id") ?? "");
      return new Response("{}", { status: 200, headers: { "content-type": "application/json" } });
    });
    vi.stubGlobal("fetch", fetchMock);

    const client = api.extend({ baseUrl: "http://localhost" });
    await client.get("teams").json();
    await client.get("teams").json();

    expect(seen).toHaveLength(2);
    const uuidLike = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
    expect(seen[0]).toMatch(uuidLike);
    expect(seen[1]).toMatch(uuidLike);
    expect(seen[0]).not.toBe(seen[1]);
  });

  it("pose un UUID v4 valide même sans crypto.randomUUID (contexte non sécurisé)", async () => {
    // Régression du 2026-08-14 : `crypto.randomUUID` n'existe QUE dans un contexte
    // sécurisé (https ou localhost). Sur un accès http hors localhost (e2e dockerisé
    // via frontend-dev, poste du LAN), le hook jetait AVANT le fetch — l'app entière
    // affichait « Une erreur est survenue » sans qu'aucune requête ne parte.
    const seen: string[] = [];
    const fetchMock = vi.fn(async (input: Request | string | URL, init?: RequestInit) => {
      const request = input instanceof Request ? input : new Request(input, init);
      seen.push(request.headers.get("X-Request-Id") ?? "");
      return new Response("{}", { status: 200, headers: { "content-type": "application/json" } });
    });
    vi.stubGlobal("fetch", fetchMock);
    // Un crypto SANS randomUUID mais AVEC getRandomValues — l'état réel d'un
    // navigateur en contexte non sécurisé.
    vi.stubGlobal("crypto", { getRandomValues: crypto.getRandomValues.bind(crypto) });

    const client = api.extend({ baseUrl: "http://localhost" });
    await client.get("teams").json();

    // v4 strict : version « 4 » et variante 8/9/a/b — la forme que le backend valide.
    expect(seen[0]).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
  });
});
