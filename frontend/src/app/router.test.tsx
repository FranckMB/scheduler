import { describe, expect, it } from "vitest";

import { routes } from "./router";

/**
 * NR du découpage en chunks (P4-6) — les FILETS, pas le contenu des pages.
 *
 * Découper est gratuit tant que trois choses existent ; les perdre ne casse
 * aucun test de page, mais transforme un incident réseau banal en panne muette :
 *  - `errorElement` : sinon un chunk 404 (déploiement pendant la session) remplace
 *    toute l'app par l'écran anglais du router, invisible de Sentry ;
 *  - `HydrateFallback` : sinon react-router rend `null` — page BLANCHE à chaque
 *    ouverture directe ou F5 d'une route lazy ;
 *  - les GARDES eager : un garde lazy ferait télécharger son chunk pour décider
 *    d'une redirection.
 */
describe("router — les filets du découpage", () => {
  const root = routes[0];

  it("la racine porte errorElement ET HydrateFallback", () => {
    expect(root.errorElement).toBeDefined();
    expect(root.HydrateFallback).toBeDefined();
  });

  it("toutes les routes de page vivent SOUS la racine (donc couvertes par les filets)", () => {
    // Une route ajoutée à côté de la racine échapperait aux deux filets sans que
    // rien ne le signale — c'est le mode de régression de ce découpage.
    expect(routes).toHaveLength(1);
    expect(root.children?.length).toBeGreaterThan(5);
  });

  it("les gardes d'accès restent EAGER (jamais `lazy`)", () => {
    const guarded = collect(root).filter((r) => undefined !== r.element && undefined === r.path);
    // AuthGuard et AdminGuard : ils décident d'une redirection, ils ne doivent pas
    // coûter un aller-retour réseau.
    expect(guarded.length).toBeGreaterThanOrEqual(1);
    for (const route of guarded) {
      expect(route.lazy).toBeUndefined();
    }
    const admin = root.children?.find((r) => "/admin" === r.path);
    expect(admin?.element).toBeDefined();
    expect(admin?.lazy).toBeUndefined();
  });

  it("les pages lourdes sont bien LAZY (sinon le découpage ne sert à rien)", () => {
    const byPath = new Map(collect(root).map((r) => [r.path, r]));
    for (const path of ["/wizard", "/planning", "/matchs", "/club", "/doleances/:token"]) {
      expect(byPath.get(path)?.lazy, `${path} doit rester lazy`).toBeDefined();
    }
  });
});

/** Aplatit l'arbre de routes. */
function collect(route: (typeof routes)[number]): Array<(typeof routes)[number]> {
  return [route, ...(route.children ?? []).flatMap(collect)];
}
