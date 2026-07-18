import * as Sentry from "@sentry/react";
import { Component, type ErrorInfo, type ReactNode } from "react";

interface Props {
  children: ReactNode;
}

interface State {
  hasError: boolean;
}

/**
 * FRT-08: top-level error boundary. Without it, any throw during render (a bad API
 * shape, a null deref) unmounts the whole tree to a blank white screen. This catches
 * it and shows a branded, actionable French fallback instead. Placed OUTSIDE the app
 * providers so it also survives a throw in the theme/query setup.
 */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { hasError: false };

  static getDerivedStateFromError(): State {
    return { hasError: true };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // Console d'abord (trace récupérable même sans DSN), puis Sentry — no-op si le
    // SDK n'est pas initialisé (INF-01 : câblé, activé par VITE_SENTRY_DSN).
    console.error("Unhandled render error:", error, info.componentStack);
    Sentry.captureException(error, { contexts: { react: { componentStack: info.componentStack } } });
  }

  render(): ReactNode {
    if (!this.state.hasError) {
      return this.props.children;
    }
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-4 bg-background p-6 text-center text-foreground">
        <h1 className="text-lg font-semibold">Une erreur inattendue s'est produite</h1>
        <p className="max-w-md text-sm text-muted-foreground">
          L'application a rencontré un problème. Vos données ne sont pas perdues — réessayez, ou rechargez la page si le problème persiste.
        </p>
        <div className="flex gap-2">
          {/* Retry re-renders the children in place: a transient throw (a racing query,
              a route-transition blip) recovers without a full reload + re-auth. */}
          <button
            type="button"
            onClick={() => this.setState({ hasError: false })}
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
}
