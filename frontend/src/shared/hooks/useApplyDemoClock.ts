import { useEffect } from "react";
import { useQueryClient } from "@tanstack/react-query";

import { useMe } from "@/features/auth/queries";
import { applyServerToday, todayISO } from "@/shared/lib/clock";

/**
 * P4-16/P2-4 — cale le « aujourd'hui » du front sur celui du SERVEUR pour un club
 * démo (`me.club.demoToday`), pour que l'écran et le serveur disent la même date.
 *
 * Monté dans AppLayout, comme le thème club. L'application (ou le relâchement) ne
 * change `todayISO()` que pour les rendus SUIVANTS : quand la date effective bouge,
 * on invalide le cache des requêtes — les écrans déjà montés (radar, calendrier)
 * se recalculent au lieu de garder des cartes datées de l'ancien « aujourd'hui ».
 * Un vrai club a `demoToday` null : `applyServerToday(null)` relâche, et comme la
 * date effective ne bouge pas, aucune invalidation — zéro coût hors démo.
 */
export function useApplyDemoClock(): void {
  const { data: me } = useMe();
  const queryClient = useQueryClient();
  const demoToday = me?.club?.demoToday ?? null;

  useEffect(() => {
    const before = todayISO();
    applyServerToday(demoToday);
    if (todayISO() !== before) {
      void queryClient.invalidateQueries();
    }
  }, [demoToday, queryClient]);
}
