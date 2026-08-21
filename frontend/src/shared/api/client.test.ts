import { afterEach, describe, expect, it, vi } from "vitest";

import { consumeSessionExpired } from "@/shared/lib/sessionExpiredNotice";
import { useAuthStore } from "@/shared/stores/authStore";

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

/**
 * P2-4 (revue sécu) — un 401 ailleurs qu'à la connexion = session périmée → le client
 * vide l'état et redirige vers /login. MAIS le raccourci démo (`/api/dev/demo-register`)
 * répond 401 sur un MOT DE PASSE incorrect alors que PERSONNE n'est connecté (l'inscrit
 * est sur le formulaire). Le confondre avec une session expirée éjecterait le fondateur
 * vers /login en pleine démo. Cette route est donc exemptée, comme /api/login.
 */
describe("api client — 401 handling", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    useAuthStore.getState().setAuthenticated(true);
    window.sessionStorage.clear();
  });

  function stub401(): void {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => new Response('{"error":"invalid_credentials"}', { status: 401, headers: { "content-type": "application/json" } })),
    );
  }

  it("un 401 sur le raccourci démo n'éjecte PAS la session (ni clear, ni redirect)", async () => {
    stub401();
    const assign = vi.fn();
    vi.stubGlobal("location", { assign });
    useAuthStore.getState().setAuthenticated(true);

    const client = api.extend({ baseUrl: "http://localhost/api/" });
    await expect(client.post("dev/demo-register").json()).rejects.toBeDefined();

    expect(useAuthStore.getState().isAuthenticated).toBe(true);
    expect(assign).not.toHaveBeenCalled();
  });

  it("un 401 sur une AUTRE route éjecte bien la session (l'exemption reste étroite)", async () => {
    stub401();
    const assign = vi.fn();
    vi.stubGlobal("location", { assign });
    useAuthStore.getState().setAuthenticated(true);

    const client = api.extend({ baseUrl: "http://localhost/api/" });
    await expect(client.get("teams").json()).rejects.toBeDefined();

    expect(useAuthStore.getState().isAuthenticated).toBe(false);
    expect(assign).toHaveBeenCalledWith("/login");
  });

  // P5-14 — un 401 hors login POSE le marqueur d'expiration AVANT de rediriger :
  // LoginPage l'affichera au lieu d'une redirection muette.
  it("un 401 hors login marque l'expiration (lu par LoginPage)", async () => {
    stub401();
    vi.stubGlobal("location", { assign: vi.fn() });
    useAuthStore.getState().setAuthenticated(true);

    const client = api.extend({ baseUrl: "http://localhost/api/" });
    await expect(client.get("teams").json()).rejects.toBeDefined();

    expect(consumeSessionExpired()).toBe(true);
  });

  it("un 401 sur /api/login ne marque PAS d'expiration (mauvais identifiants, personne n'était connecté)", async () => {
    stub401();
    vi.stubGlobal("location", { assign: vi.fn() });
    useAuthStore.getState().setAuthenticated(true);

    const client = api.extend({ baseUrl: "http://localhost/api/" });
    await expect(client.post("login").json()).rejects.toBeDefined();

    expect(consumeSessionExpired()).toBe(false);
  });
});
