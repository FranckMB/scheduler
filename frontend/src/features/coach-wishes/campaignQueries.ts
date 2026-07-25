import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import * as campaignApi from "./campaignApi";
import type { CoachWishCampaignPayload } from "./campaignApi";

/**
 * Toutes les campagnes de collecte du club (une par période) — le radar y lit les
 * compteurs de réponse et retrouve la campagne d'une carte par son calendarEntryId.
 * Une seule requête pour tout le radar (pas un hook par carte : règles des hooks).
 */
export function useCoachWishCampaigns() {
  return useQuery({
    queryKey: ["coach-wish-campaigns"],
    queryFn: () => campaignApi.listCoachWishCampaigns(),
    staleTime: 30_000,
  });
}

export function useCreateCoachWishCampaign() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: CoachWishCampaignPayload) => campaignApi.createCoachWishCampaign(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["coach-wish-campaigns"] }),
  });
}

export function useUpdateCoachWishCampaign() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: CoachWishCampaignPayload }) => campaignApi.updateCoachWishCampaign(id, body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["coach-wish-campaigns"] }),
  });
}
