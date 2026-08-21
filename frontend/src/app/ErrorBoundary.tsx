import * as Sentry from "@sentry/react";
import { Component, type ErrorInfo, type ReactNode } from "react";

import { ServerErrorScreen } from "@/app/ServerErrorScreen";

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
    // P5-14 — l'écran 500 partagé. « Réessayer » re-rend les enfants EN PLACE : un
    // throw passager (query en course, à-coup de transition) se rétablit sans
    // rechargement + ré-auth. Toute la mécanique de la classe (componentDidCatch →
    // Sentry, retry par setState) est conservée — seul le RENDU passe à la primitive.
    // Monté HORS providers : d'où l'écran 500 (sans FeedbackDialog), pas l'écran
    // hors-ligne (dont le support a besoin des providers).
    return <ServerErrorScreen onRetry={() => this.setState({ hasError: false })} />;
  }
}
