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
        // season (mono-season clubs never send it).
        const seasonId = useSeasonStore.getState().selectedSeasonId;
        if (seasonId) {
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
        // Clearing drops the header, so a reload cannot loop.
        if (state.response.status === 403 && state.request.headers.has("X-Season-Id")) {
          useSeasonStore.getState().clear();
          if (typeof window !== "undefined") {
            window.location.reload();
          }
        }
      },
    ],
    beforeError: [
      // Read the server's error body HERE (still unconsumed) and stash a friendly
      // message on the error; errorMessage() reads it. Reading the body post-hoc
      // from the thrown HTTPError fails (stream already gone), which left toasts
      // showing a generic status message instead of the real reason.
      async (state) => {
        const { error } = state;
        if (error instanceof HTTPError) {
          try {
            const body = (await error.response.clone().json()) as {
              error?: string;
              message?: string;
              detail?: string;
              violations?: { message?: string }[];
            };
            const direct = body.error ?? body.message ?? body.detail;
            let message = typeof direct === "string" ? direct.trim() : "";
            if (message === "" && Array.isArray(body.violations)) {
              message = body.violations
                .map((v) => v.message)
                .filter((m): m is string => typeof m === "string" && m.trim() !== "")
                .join(" · ");
            }
            if (message !== "") {
              (error as { serverMessage?: string }).serverMessage = message;
            }
          } catch {
            // body not JSON → errorMessage falls back to a status-based sentence
          }
        }
        return error;
      },
    ],
  },
});
