import * as Sentry from "@sentry/react";
import { useEffect } from "react";
import { useNavigate, useRouteError } from "react-router";

/**
 * Le filet des erreurs de ROUTE — indispensable depuis le découpage en chunks (P4-6).
 *
 * `ErrorBoundary` (React) ne voit rien de ce qui casse ici : le data router attrape
 * lui-même la rejection d'un `lazy` et rend son écran par défaut — non stylé, en
 * anglais, sans issue — À LA PLACE DE TOUTE L'APPLICATION, et sans que Sentry
 * n'en sache jamais rien.
 *
 * Deux causes produisent le MÊME message navigateur (« Failed to fetch dynamically
 * imported module ») et le navigateur ne les distingue pas :
 *  - un déploiement a remplacé les `assets/*.js` hachés pendant la session ;
 *  - le réseau a lâché (mobile, wifi de gymnase, onglet hors ligne).
 *
 * Les confondre est nuisible dans les deux sens : dire « rechargez » à quelqu'un
 * hors ligne lui fait perdre le shell encore utilisable pour tomber sur la page
 * d'erreur du navigateur. On tranche donc sur `navigator.onLine`, et on offre
 * TOUJOURS un « Réessayer » qui rejoue la navigation sans recharger le document.
 */
export function RouteErrorBoundary() {
  const error = useRouteError();
  const navigate = useNavigate();
  const chunkFailed = isChunkLoadError(error);
  const offline = "undefined" !== typeof navigator && false === navigator.onLine;
  const staleDeploy = chunkFailed && !offline;

  useEffect(() => {
    // Console d'abord (trace lisible sans DSN), puis Sentry — no-op sans SDK.
    console.error("Route error:", error);
    Sentry.captureException(error, {
      tags: { chunkFailed: String(chunkFailed), offline: String(offline) },
    });
  }, [error, chunkFailed, offline]);

  return (
    <div className="flex flex-col items-center justify-center gap-4 p-10 text-center text-foreground">
      <h1 className="text-lg font-semibold">
        {offline ? "Vous semblez hors ligne" : staleDeploy ? "Une nouvelle version est disponible" : "Cette page n'a pas pu être ouverte"}
      </h1>
      <p className="max-w-md text-sm text-muted-foreground">
        {offline
          ? "Le chargement de cette page a échoué faute de connexion. Reconnectez-vous au réseau, puis réessayez — le reste de l'application reste utilisable."
          : staleDeploy
            ? "L'application a été mise à jour pendant votre navigation. Rechargez la page pour continuer — vos données ne sont pas perdues."
            : "Le chargement de cette page a échoué. Réessayez, ou rechargez la page si le problème persiste."}
      </p>
      <div className="flex gap-2">
        {/* Réessayer AVANT recharger : une panne passagère (paquet perdu) se rejoue
            sans perdre l'état non persisté — c'est le geste que l'ErrorBoundary
            React offre déjà pour les throws de rendu. */}
        <button
          type="button"
          onClick={() => void navigate(0)}
          className="rounded-md bg-accent px-4 py-2 text-sm font-medium text-accent-foreground hover:opacity-90"
        >
          Réessayer
        </button>
        <button
          type="button"
          onClick={() => window.location.reload()}
          className="rounded-md border border-border px-4 py-2 text-sm font-medium hover:bg-muted"
        >
          Recharger la page
        </button>
      </div>
    </div>
  );
}

/**
 * Un chunk absent se présente différemment selon le navigateur : `TypeError:
 * Failed to fetch dynamically imported module` (Chrome/Safari), `error loading
 * dynamically imported module` (Firefox), ou un `ChunkLoadError` nommé. On teste
 * le message plutôt que le type, faute d'erreur normalisée — et on ne conclut
 * PAS à un déploiement : seul `navigator.onLine` sépare les deux causes.
 */
function isChunkLoadError(error: unknown): boolean {
  if (!(error instanceof Error)) {
    return false;
  }
  const text = `${error.name} ${error.message}`.toLowerCase();

  return text.includes("chunkloaderror")
    || text.includes("failed to fetch dynamically imported module")
    || text.includes("error loading dynamically imported module");
}
