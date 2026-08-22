/**
 * Hooks de la SESSION (`me`, saison de travail) — voir le docblock de `./api`
 * pour POURQUOI la session est du socle partagé (`app/` + 4 modules `shared/`,
 * remontée AUD-FRT-21 résorbée en P4-123) et pourquoi api/queries sont scindés
 * (couture de mock D-31 : les tests mockent ces hooks sans charger `./api`).
 */
import { useQuery } from "@tanstack/react-query";

import { useAuthStore } from "@/shared/stores/authStore";
import { useSeasonStore } from "@/shared/stores/seasonStore";

import { getMe, type MeSeason } from "./api";

/** Current user + club + membership status (server source of truth). */
export function useMe() {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated);
  return useQuery({
    queryKey: ["me"],
    queryFn: getMe,
    enabled: isAuthenticated,
    retry: false,
    staleTime: 60_000,
  });
}

/**
 * The season the user is WORKING IN: the explicit selection (X-Season-Id,
 * seasonStore) first, else the calendar-current one. null while `me` loads.
 * Même dérivation que PlanningPage (workingSeason) — source partagée.
 */
export function useWorkingSeason(): MeSeason | null {
  const { data: me } = useMe();
  const selectedSeasonId = useSeasonStore((s) => s.selectedSeasonId);
  return (
    me?.seasons.find((sn) => sn.id === selectedSeasonId)
    ?? me?.seasons.find((sn) => sn.id === (me.currentSeasonId ?? ""))
    ?? me?.seasons.find((sn) => sn.isCurrent)
    ?? null
  );
}
