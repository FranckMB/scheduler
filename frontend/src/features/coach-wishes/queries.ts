import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import * as coachWishApi from "./api";
import type { CoachWishPayload } from "./api";

/** La todo-list des doléances d'une période, clé sur l'entrée MÈRE des vacances. */
export function useCoachWishes(calendarEntryId: string | null) {
  return useQuery({
    queryKey: ["coach-wishes", calendarEntryId],
    queryFn: () => coachWishApi.listCoachWishes(calendarEntryId as string),
    enabled: null !== calendarEntryId,
    staleTime: 30_000,
  });
}

export function useCreateCoachWish() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: CoachWishPayload) => coachWishApi.createCoachWish(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["coach-wishes"] }),
  });
}

export function useUpdateCoachWish() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: CoachWishPayload }) => coachWishApi.updateCoachWish(id, body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["coach-wishes"] }),
  });
}

export function useDeleteCoachWish() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => coachWishApi.deleteCoachWish(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["coach-wishes"] }),
  });
}
