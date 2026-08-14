import * as Sentry from "@sentry/react";
import ky from "ky";

import { recordIncident } from "@/shared/api/lastIncidentStore";
import { useAuthStore } from "@/shared/stores/authStore";
import { useSeasonStore } from "@/shared/stores/seasonStore";

/**
 * Configured HTTP client. Relative `/api` prefix only (Vite proxy in dev, Nginx
 * in prod — never hardcode hosts). Clears auth on 401.
 * ky 2.x hooks receive a single `state` object ({ request, response, ... }).
 *
 * SEC-16 (audit) : plus d'en-tête `Authorization` — l'identité voyage dans le
 * cookie httpOnly posé par le serveur, que le JS ne voit pas. `credentials`
 * reste explicite : le défaut `same-origin` suffirait (l'API est servie sous la
 * même origine par les proxys vite/nginx), mais l'écrire évite qu'un futur appel
 * cross-origin parte muet, sans identité et sans erreur parlante.
 */
/** UUID v4 sans dépendre du contexte sécurisé (cf. commentaire du hook P5-11). */
function randomUuid(): string {
  // Pas de narrowing `in` : le type DOM `Crypto` déclare toujours randomUUID, la
  // branche « absent » serait `never` — or à l'exécution elle existe bel et bien.
  const c = crypto as Crypto & { randomUUID?: () => string };
  if (typeof c.randomUUID === "function") {
    return c.randomUUID();
  }
  const b = c.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = [...b].map((x) => x.toString(16).padStart(2, "0")).join("");
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

export const api = ky.create({
  prefix: "/api",
  credentials: "include",
  hooks: {
    beforeRequest: [
      (state) => {
        // P5-11 — id de corrélation unique par requête (front→backend→bus→engine).
        // Le backend le VALIDE (forme UUID) et le ré-émet. ⚠ `crypto.randomUUID`
        // n'existe QUE dans un contexte sécurisé (https ou localhost) : sur un
        // accès http hors localhost (e2e dockerisé via frontend-dev, poste du LAN),
        // l'appeler jetait AVANT le fetch — toute l'app rendait « Une erreur est
        // survenue » sans qu'aucune requête ne parte. Repli : un UUID v4 dérivé de
        // `getRandomValues`, disponible dans tous les contextes.
        state.request.headers.set("X-Request-Id", randomUuid());
      },
      (state) => {
        // Season the manager is working in — absent = server-derived current
        // season (mono-season clubs never send it). A request that already
        // carries the header wins: one-shot cross-season calls (transition
        // re-dating) target another season explicitly — the server validates
        // the header either way, it is never trusted client-side.
        const seasonId = useSeasonStore.getState().selectedSeasonId;
        if (seasonId && !state.request.headers.has("X-Season-Id")) {
          state.request.headers.set("X-Season-Id", seasonId);
        }
      },
    ],
    afterResponse: [
      (state) => {
        // P5-11 — sur une erreur serveur, étiqueter la trace Sentry avec le
        // request_id ré-émis : l'incident remonté par l'utilisateur se retrouve
        // dans les logs corrélés. Gardé par VITE_SENTRY_DSN (cohérent avec
        // l'init de main.tsx) — sans DSN le SDK est inerte, on n'appelle rien.
        if (import.meta.env.VITE_SENTRY_DSN && state.response.status >= 500) {
          const requestId = state.response.headers.get("X-Request-Id");
          if (requestId) {
            Sentry.setTag("request_id", requestId);
          }
        }
      },
      (state) => {
        // P5-6 — retenir le dernier incident serveur (request_id ré-émis) pour le
        // proposer à un signalement contextuel ouvert dans la foulée. Indépendant
        // de Sentry (le canal de signalement vit sans DSN) ; hook distinct des deux
        // autres pour ne rien changer à leur comportement.
        if (state.response.status >= 500) {
          const requestId = state.response.headers.get("X-Request-Id");
          if (requestId) {
            recordIncident(requestId);
          }
        }
      },
      (state) => {
        // 401 on the login endpoint is a normal "bad credentials" — let the caller
        // handle it. Only treat 401 elsewhere as a stale/expired session.
        const isLogin = state.request.url.includes("/api/login");
        if (state.response.status === 401 && !isLogin) {
          useAuthStore.getState().clear();
          if (typeof window !== "undefined") {
            window.location.assign("/login");
          }
        }
        // Self-healing on a stale persisted season (e.g. purged server-side):
        // the backend 403s EVERY request carrying the dead X-Season-Id,
        // /api/me included — without this reset the app could never recover.
        // Keyed on the X-Season-Rejected marker (NOT any 403) so a legitimate
        // authorization denial still surfaces its error instead of wiping the
        // selection and hard-reloading. Clearing drops the header → no loop.
        if (state.response.status === 403 && state.response.headers.has("X-Season-Rejected")) {
          useSeasonStore.getState().clear();
          if (typeof window !== "undefined") {
            window.location.reload();
          }
        }
      },
    ],
    // No beforeError hook: ky 2.x consumes the error-response body itself and
    // exposes the parsed result as `error.data` BEFORE any consumer runs —
    // re-reading `error.response` throws "body stream already read". Every
    // error-body reader (errorMessage(), structured catches) must read
    // `error.data`, never the response.
  },
});
