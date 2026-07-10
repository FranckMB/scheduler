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
    // No Sentry yet (INF-01) — log to the console so the trace is recoverable.
    console.error("Unhandled render error:", error, info.componentStack);
  }

  render(): ReactNode {
    if (!this.state.hasError) {
      return this.props.children;
    }
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-4 bg-background p-6 text-center text-foreground">
        <h1 className="text-lg font-semibold">Une erreur inattendue s'est produite</h1>
        <p className="max-w-md text-sm text-muted-foreground">
          L'application a rencontré un problème. Vos données ne sont pas perdues — rechargez la page pour continuer.
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
}
