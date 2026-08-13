import { useEffect, useId, useRef, useState } from "react";

/**
 * P5-3b — widget Cloudflare Turnstile (preuve d'humanité sur l'inscription).
 *
 * Ne charge le script tiers `challenges.cloudflare.com` QUE lorsqu'une sitekey est
 * fournie (le parent ne rend ce composant que si Turnstile est actif). Rendu
 * EXPLICITE : le widget est monté une fois par `window.turnstile.render`, et son
 * identifiant retenu pour pouvoir le réarmer.
 *
 * ⚠ Le token Turnstile est à USAGE UNIQUE (et expire à ~300 s) : après un submit
 * refusé côté serveur, le parent incrémente `resetNonce` pour réarmer le widget et
 * obtenir un token frais — sinon la 2ᵉ tentative renverrait un token déjà consommé.
 */
const TURNSTILE_SCRIPT_SRC = "https://challenges.cloudflare.com/turnstile/v0/api.js";

interface TurnstileRenderOptions {
  sitekey: string;
  callback: (token: string) => void;
  "expired-callback": () => void;
  "error-callback": () => void;
  theme: "auto";
}

interface TurnstileGlobal {
  render: (container: HTMLElement, options: TurnstileRenderOptions) => string;
  reset: (widgetId?: string) => void;
  remove: (widgetId?: string) => void;
}

declare global {
  interface Window {
    turnstile?: TurnstileGlobal;
  }
}

export interface TurnstileWidgetProps {
  /** Sitekey publique servie par /api/register/config. */
  siteKey: string;
  /** Token de challenge résolu (à threader dans le payload de register). */
  onVerify: (token: string) => void;
  /** Le token précédent a expiré : le parent doit l'oublier. */
  onExpire: () => void;
  /** Un incrément réarme le widget (après un submit refusé — token à usage unique). */
  resetNonce: number;
}

export function TurnstileWidget({ siteKey, onVerify, onExpire, resetNonce }: TurnstileWidgetProps) {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const widgetIdRef = useRef<string | null>(null);
  const labelId = useId();
  const [failed, setFailed] = useState(false);

  // Callbacks toujours frais SANS re-monter le widget (une seule instance) — mis à
  // jour dans un effet, jamais pendant le rendu (un ref n'est pas de la donnée de rendu).
  const onVerifyRef = useRef(onVerify);
  const onExpireRef = useRef(onExpire);
  useEffect(() => {
    onVerifyRef.current = onVerify;
    onExpireRef.current = onExpire;
  });

  useEffect(() => {
    let cancelled = false;

    function renderWidget() {
      const turnstile = window.turnstile;
      const container = containerRef.current;
      if (cancelled || !turnstile || !container || null !== widgetIdRef.current) {
        return;
      }
      widgetIdRef.current = turnstile.render(container, {
        sitekey: siteKey,
        callback: (token) => onVerifyRef.current(token),
        "expired-callback": () => onExpireRef.current(),
        "error-callback": () => setFailed(true),
        theme: "auto",
      });
    }

    if (window.turnstile) {
      renderWidget();
      return () => {
        cancelled = true;
      };
    }

    // Charge le script une seule fois, puis rend au load.
    const existing = document.querySelector<HTMLScriptElement>(`script[src="${TURNSTILE_SCRIPT_SRC}"]`);
    if (existing) {
      existing.addEventListener("load", renderWidget, { once: true });
    } else {
      const script = document.createElement("script");
      script.src = TURNSTILE_SCRIPT_SRC;
      script.async = true;
      script.defer = true;
      script.addEventListener("load", renderWidget, { once: true });
      script.addEventListener("error", () => {
        if (!cancelled) setFailed(true);
      }, { once: true });
      document.head.appendChild(script);
    }

    return () => {
      cancelled = true;
    };
  }, [siteKey]);

  // Réarmement : le token est à usage unique, on redemande un challenge frais.
  useEffect(() => {
    if (resetNonce > 0 && window.turnstile && null !== widgetIdRef.current) {
      setFailed(false);
      window.turnstile.reset(widgetIdRef.current);
    }
  }, [resetNonce]);

  return (
    <div className="flex flex-col gap-1.5">
      <span id={labelId} className="sr-only">
        Vérification anti-robot
      </span>
      <div ref={containerRef} aria-labelledby={labelId} />
      {failed ? (
        <p role="alert" className="text-xs text-destructive">
          La vérification anti-robot n'a pas pu se charger. Rechargez la page et réessayez.
        </p>
      ) : null}
    </div>
  );
}
