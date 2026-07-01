import { create } from "zustand";
import { persist } from "zustand/middleware";

export type MembershipStatus = "none" | "pending" | "active";

interface AuthState {
  token: string | null;
  setToken: (token: string | null) => void;
  clear: () => void;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      token: null,
      setToken: (token) => set({ token }),
      clear: () => set({ token: null }),
    }),
    {
      name: "cs-auth",
      version: 1,
      // Zustand 5: persistedState may be null — null-check before use (anti-pattern #3).
      migrate: (persistedState) => {
        if (persistedState === null || typeof persistedState !== "object") {
          return { token: null } as AuthState;
        }
        return persistedState as AuthState;
      },
    },
  ),
);
