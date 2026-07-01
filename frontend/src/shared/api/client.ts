import ky from "ky";

import { useAuthStore } from "@/shared/stores/authStore";

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
      },
    ],
    afterResponse: [
      (state) => {
        if (state.response.status === 401) {
          useAuthStore.getState().clear();
          if (typeof window !== "undefined") {
            window.location.assign("/login");
          }
        }
      },
    ],
  },
});
