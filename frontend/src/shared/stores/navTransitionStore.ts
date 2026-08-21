import { create } from "zustand";

/**
 * Signal de TRANSITION de navigation déclenchée par le gestionnaire — le seul déclencheur du
 * contexte « Changement de page » du voile bloquant (`app/ActionVeil`).
 *
 * RÈGLE (lot C, corrigée le 2026-08-21, GO fondateur) : le voile « page » ne s'arme QUE sur un
 * GESTE de navigation (changement d'étape du wizard, de vue/version du planning), JAMAIS sur le
 * premier montage d'un écran. Pourquoi c'est écrit ici, pas déduit : le blocage anti-double-clic
 * sert à manger le 2ᵉ clic d'un geste DÉJÀ parti. Une simple arrivée n'a rien lancé — il n'y a
 * aucune double-soumission à empêcher, donc aucune raison de geler un formulaire déjà peint. Et
 * comme le voile est invisible sous 250 ms, geler là mange les frappes SANS le moindre retour
 * visuel : le pire mode d'échec, celui où l'utilisateur croit que son clavier est mort.
 *
 * `token` est un compteur monotone : chaque geste l'incrémente. `ActionVeil` compare au dernier
 * token VU (au montage il adopte la valeur courante — une arrivée n'est jamais une transition) et
 * n'ouvre sa fenêtre que sur un token NOUVEAU. Impératif (`armNavTransition`) pour être appelé
 * depuis les actions des stores, hors d'un composant.
 */
interface NavTransitionState {
  token: number;
  arm: () => void;
}

export const useNavTransition = create<NavTransitionState>((set) => ({
  token: 0,
  arm: () => set((s) => ({ token: s.token + 1 })),
}));

/** Arme une transition de navigation. À appeler depuis une action de navigation d'un store. */
export const armNavTransition = (): void => useNavTransition.getState().arm();
