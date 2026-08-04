import { create } from "zustand";
import { persist } from "zustand/middleware";

// P3-4 : club_pending = demande de CRÉATION en attente d'approbation (club FFBB ou superadmin).
export type MembershipStatus = "none" | "pending" | "club_pending" | "active";

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
