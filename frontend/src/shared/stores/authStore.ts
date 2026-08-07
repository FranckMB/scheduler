import { create } from "zustand";
import { persist } from "zustand/middleware";

// P3-4 : club_pending = demande de CRÉATION en attente d'approbation (club FFBB ou superadmin).
export type MembershipStatus = "none" | "pending" | "club_pending" | "active";

/**
 * SEC-16 (audit) — ce store NE PORTE PLUS DE JETON.
 *
 * Le JWT vivait ici, persisté en clair dans `localStorage` : une XSS, une
 * extension véreuse ou une console ouverte sur un poste partagé le lisait et le
 * rejouait AILLEURS pendant toute sa durée de vie. Il part désormais en cookie
 * httpOnly posé par le serveur (`docs/security/jwt-cookie.md`) : le JS ne le voit
 * pas, donc ne peut pas le voler.
 *
 * Ce qui reste ici est un simple INDICE D'UI — « une session a été ouverte dans
 * cet onglet » — qui évite un aller-retour clignotant vers /login au chargement.
 * Ce n'est PAS une autorisation : la frontière est le serveur, qui répond 401 si
 * le cookie a expiré (le client vide alors le drapeau, `shared/api/client.ts`).
 * Un drapeau à `true` sans cookie valide ne donne accès à rien.
 */
interface AuthState {
  isAuthenticated: boolean;
  setAuthenticated: (value: boolean) => void;
  clear: () => void;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      isAuthenticated: false,
      setAuthenticated: (value) => set({ isAuthenticated: value }),
      clear: () => set({ isAuthenticated: false }),
    }),
    {
      name: "cs-auth",
      // v2 : le jeton disparaît du stockage. La migration l'EFFACE au lieu de le
      // reporter — sans ça, le jeton d'une session déjà ouverte resterait en
      // localStorage après déploiement, et la faille survivrait à son correctif.
      version: 2,
      // Zustand 5: persistedState may be null — null-check before use (anti-pattern #3).
      migrate: (persistedState) => {
        if (persistedState === null || typeof persistedState !== "object") {
          return { isAuthenticated: false } as AuthState;
        }
        const legacyToken = (persistedState as { token?: unknown }).token;

        return { isAuthenticated: "string" === typeof legacyToken && "" !== legacyToken } as AuthState;
      },
    },
  ),
);
