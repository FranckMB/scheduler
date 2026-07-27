import { describe, expect, it } from "vitest";

import { routes } from "./router";

/**
 * NR du découpage en chunks (P4-6) — les FILETS, pas le contenu des pages.
 *
 * Découper est gratuit tant que ces garde-fous existent ; les perdre ne casse
 * aucun test de page, mais transforme un incident réseau banal en panne muette :
 *  - `errorElement` à la racine ET sur `AppLayout` : sans le second, l'échec du
 *    chunk d'UNE page démonte l'en-tête, la navigation et les bandeaux ;
 *  - `HydrateFallback` : sinon react-router rend `null` — page BLANCHE à chaque
 *    ouverture directe ou F5 d'une route lazy ;
 *  - le shell racine : il porte le retour d'attente de TOUTE navigation, zone
 *    non authentifiée comprise (le tunnel d'inscription y enchaîne ses étapes).
 */
describe("router — les filets du découpage", () => {
  const root = routes[0];

  it("la racine porte le shell d'attente, errorElement ET HydrateFallback", () => {
    expect(root.element).toBeDefined();
    expect(root.errorElement).toBeDefined();
    expect(root.HydrateFallback).toBeDefined();
  });

  it("toutes les routes de page vivent SOUS la racine (donc couvertes par les filets)", () => {
    // Une route ajoutée à côté de la racine échapperait aux deux filets sans que
    // rien ne le signale — c'est le mode de régression de ce découpage.
    expect(routes).toHaveLength(1);
    expect(root.children?.length).toBeGreaterThan(5);
  });

  it("le shell authentifié porte SON PROPRE errorElement (l'app survit à l'échec d'une page)", () => {
    const appLayout = findLayoutWithChildren(root, ["/planning", "/wizard"]);
    expect(appLayout, "le groupe de routes authentifiées est introuvable").toBeDefined();
    expect(appLayout?.errorElement).toBeDefined();
  });

  it("les gardes d'accès sont EAGER — nommément, pas par déduction", () => {
    // La version précédente de ce test filtrait les routes PARCE QU'elles avaient
    // un `element` : passer un garde en `lazy` le faisait sortir du filtre et le
    // test restait vert. On vise donc les routes par leur chemin.
    const admin = root.children?.find((r) => "/admin" === r.path);
    expect(admin?.element, "AdminGuard doit rester eager").toBeDefined();
    expect(admin?.lazy).toBeUndefined();

    const authed = findLayoutWithChildren(root, ["/planning", "/wizard"]);
    const guard = root.children?.find((r) => r.children?.includes(authed as never));
    expect(guard?.element, "AuthGuard doit rester eager").toBeDefined();
    expect(guard?.lazy).toBeUndefined();
  });

  it("la redirection des URL /admin inconnues ne dépend PAS du chunk de la console", () => {
    const admin = root.children?.find((r) => "/admin" === r.path);
    const catchAll = admin?.children?.find((r) => "*" === r.path);
    expect(catchAll, "le catch-all /admin doit être frère du shell, pas son enfant").toBeDefined();
    expect(catchAll?.element).toBeDefined();
  });

  it("les pages lourdes sont bien LAZY (sinon le découpage ne sert à rien)", () => {
    const byPath = new Map(collect(root).map((r) => [r.path, r]));
    for (const path of ["/wizard", "/planning", "/matchs", "/club", "/doleances/:token"]) {
      expect(byPath.get(path)?.lazy, `${path} doit rester lazy`).toBeDefined();
    }
  });
});

type Route = (typeof routes)[number];

/** Le groupe de routes qui contient ces chemins (le shell authentifié). */
function findLayoutWithChildren(root: Route, paths: string[]): Route | undefined {
  return collect(root).find((r) => paths.every((p) => r.children?.some((c) => p === c.path)));
}

/** Aplatit l'arbre de routes. */
function collect(route: Route): Route[] {
  return [route, ...(route.children ?? []).flatMap(collect)];
}
