import { create } from "zustand";

import type { AdminIdentity } from "./api";

interface AdminAuthState {
  identity: AdminIdentity | null;
  csrfToken: string | null;
  setSession: (identity: AdminIdentity, csrfToken: string) => void;
  setCsrfToken: (csrfToken: string) => void;
  clear: () => void;
}

/** In-memory only: the browser session cookie survives reloads, secrets do not. */
export const useAdminStore = create<AdminAuthState>((set) => ({
  identity: null,
  csrfToken: null,
  setSession: (identity, csrfToken) => set({ identity, csrfToken }),
  setCsrfToken: (csrfToken) => set({ csrfToken }),
  clear: () => set({ identity: null, csrfToken: null }),
}));
