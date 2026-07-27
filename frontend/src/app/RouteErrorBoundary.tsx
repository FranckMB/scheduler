import * as Sentry from "@sentry/react";
import { useEffect } from "react";
import { useRouteError } from "react-router";

/**
 * Le filet des erreurs de ROUTE — indispensable depuis le découpage en chunks (P4-6).
 *
 * `ErrorBoundary` (React) ne voit rien de ce qui casse ici : le data router attrape
 * lui-même la rejection d'un `lazy` et rend son écran par défaut — non stylé, en
 * anglais, sans issue — À LA PLACE DE TOUTE L'APPLICATION, et sans que Sentry
 * n'en sache jamais rien.
 *
 * Le cas qui arrive vraiment : un déploiement remplace les fichiers `assets/*.js`
 * hachés ; l'onglet resté ouvert demande un chunk qui n'existe plus, nginx répond
 * 404 (`try_files $uri =404`, pas de fallback SPA sur les assets). Ce n'est pas une
 * panne, c'est une version périmée : la sortie est un rechargement, et on le dit.
 */
export function RouteErrorBoundary() {
  const error = useRouteError();
  const staleChunk = isChunkLoadError(error);

  useEffect(() => {
    // Console d'abord (trace lisible sans DSN), puis Sentry — no-op sans SDK.
    console.error("Route error:", error);
    Sentry.captureException(error, { tags: { staleChunk: String(staleChunk) } });
  }, [error, staleChunk]);

  return (
    <div className="flex min-h-screen flex-col items-center justify-center gap-4 bg-background p-6 text-center text-foreground">
      <h1 className="text-lg font-semibold">{staleChunk ? "Une nouvelle version est disponible" : "Cette page n'a pas pu être ouverte"}</h1>
      <p className="max-w-md text-sm text-muted-foreground">
        {staleChunk
          ? "L'application a été mise à jour pendant votre navigation. Rechargez la page pour continuer — vos données ne sont pas perdues."
          : "Le chargement de cette page a échoué. Vérifiez votre connexion, puis réessayez."}
      </p>
      <button
        type="button"
        onClick={() => window.location.reload()}
        className="rounded-md bg-accent px-4 py-2 text-sm font-medium text-accent-foreground hover:opacity-90"
      >
        Recharger la page
      </button>
    </div>
  );
}

/**
 * Un chunk absent se présente différemment selon le navigateur : `TypeError:
 * Failed to fetch dynamically imported module` (Chrome/Safari), `error loading
 * dynamically imported module` (Firefox), ou un `ChunkLoadError` nommé. On teste
 * le message plutôt que le type, faute d'erreur normalisée.
 */
function isChunkLoadError(error: unknown): boolean {
  if (!(error instanceof Error)) {
    return false;
  }
  const text = `${error.name} ${error.message}`.toLowerCase();

  return text.includes("chunkloaderror") || (text.includes("dynamically imported module") && text.includes("import"))
    || text.includes("failed to fetch dynamically imported module")
    || text.includes("error loading dynamically imported module");
}
