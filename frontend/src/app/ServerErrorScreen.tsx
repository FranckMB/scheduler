import type { ReactNode } from "react";

import { readRecentIncidentRequestId } from "@/shared/api/lastIncidentStore";
import { SystemScreen } from "@/shared/components/ui/system-screen";

/**
 * P5-14 — le 500. Rendu par l'`ErrorBoundary` React (HORS providers), par la
 * branche « ni offline ni staleDeploy » de `RouteErrorBoundary`, et par `AuthGuard`
 * quand /api/me échoue en 5xx.
 *
 * ⚠ HORS providers : PAS de `FeedbackDialog`, PAS de router. Le geste secondaire
 * « Retour à l'accueil » est donc un `window.location.assign("/")` (on peut être
 * hors du router). `onRetry` est injecté (setState de l'ErrorBoundary, refetch de
 * /api/me, ou rejeu de navigation).
 *
 * Le code d'incident vient de `readRecentIncidentRequestId()` (fonction PURE, sûre
 * hors providers) : c'est le X-Request-Id de corrélation, aucun interne exposé. Sur
 * une 404/403 il n'y a pas d'incident serveur — la ligne ne s'affiche pas.
 */
export function ServerErrorScreen({ onRetry }: { onRetry: () => void }): ReactNode {
  return (
    <SystemScreen
      title="Arrêt de jeu imprévu."
      incidentId={readRecentIncidentRequestId()}
      primaryAction={{ label: "Réessayer", onClick: onRetry }}
      secondaryAction={{ label: "Retour à l'accueil", onClick: () => window.location.assign("/") }}
    >
      Une erreur inattendue s'est produite de notre côté. L'incident est déjà remonté à l'équipe — vos données ne sont pas perdues. Reprise du jeu dans un instant : réessayez, ou rechargez la page si ça insiste.
    </SystemScreen>
  );
}
