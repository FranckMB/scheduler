import ky from "ky";

import { useAuthStore } from "@/shared/stores/authStore";
import { useSeasonStore } from "@/shared/stores/seasonStore";

/**
 * Configured HTTP client. Relative `/api` prefix only (Vite proxy in dev, Nginx
 * in prod — never hardcode hosts). Injects the Bearer token; clears auth on 401.
 * ky 2.x hooks receive a single `state` object ({ request, response, ... }).
 */
export const api = ky.create({
  prefix: "/api",
  hooks: {
    beforeRequest: [
      (state) => {
        const token = useAuthStore.getState().token;
        if (token) {
          state.request.headers.set("Authorization", `Bearer ${token}`);
        }
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
