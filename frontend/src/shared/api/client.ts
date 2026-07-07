import ky, { HTTPError } from "ky";

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
    beforeError: [
      // ky 2.x consumes the error-response body itself and parses it into
      // `error.data` BEFORE this hook runs (cloning/re-reading the response
      // here throws "body is already used"). Normalize that parsed body into
      // `serverMessage` (read by errorMessage()) and `serverBody` (structured
      // fields for application catches, e.g. existingSeasonId on the
      // transition 409).
      (state) => {
        const { error } = state;
        if (error instanceof HTTPError) {
          const body = (error as { data?: unknown }).data;
          if (null !== body && typeof body === "object") {
            const typed = body as { error?: string; message?: string; detail?: string; violations?: { message?: string }[] };
            const direct = typed.error ?? typed.message ?? typed.detail;
            let message = typeof direct === "string" ? direct.trim() : "";
            if (message === "" && Array.isArray(typed.violations)) {
              message = typed.violations
                .map((v) => v.message)
                .filter((m): m is string => typeof m === "string" && m.trim() !== "")
                .join(" · ");
            }
            if (message !== "") {
              (error as { serverMessage?: string }).serverMessage = message;
            }
            (error as { serverBody?: unknown }).serverBody = body;
          }
        }
        return error;
      },
    ],
  },
});
