import { useState } from "react";

import { toast } from "@/shared/stores/toastStore";

import type { CalendarEntry } from "../api";
import { useCreateWeekChildren, type WeekChildrenResult } from "../queries";
import type { WeekWindow } from "./date";

/**
 * P2-5 E1 — le flux « découper une période en semaines » PARTAGÉ par le radar et
 * le DayDialog (revue #262 round 3 : il était copié-collé, un fix d'un côté
 * divergeait de l'autre). La DÉCISION d'ouvrir le picker (gating sur bloc-généré,
 * enfants, résolution des queries) reste locale — ses entrées diffèrent entre les
 * deux surfaces — mais la mutation, les toasts et l'auto-adapt à une seule semaine
 * vivent ici, une seule fois.
 */
export function useWeekAdapt(adapt: (entryId: string) => void, afterMultiCreate?: () => void) {
  const [pickerFor, setPickerFor] = useState<CalendarEntry | null>(null);
  const createWeekChildren = useCreateWeekChildren();

  const announce = ({ created, failedCount }: WeekChildrenResult): void => {
    if (failedCount > 0) {
      toast.error(`${failedCount} semaine${failedCount > 1 ? "s" : ""} n'a pas pu être créée — réessayez depuis la carte de couverture.`);
    }
    if (0 === created.length && 0 === failedCount) {
      toast.info("Ces semaines étaient déjà découpées.");
    }
  };

  // Semaines cochées → création des plans ; 1 seule (sans échec) → adapt direct,
  // plusieurs → toast + retour au cockpit (les cartes de couverture prennent le relais).
  const pickWeeks = (mother: CalendarEntry, weeks: WeekWindow[]): void => {
    createWeekChildren.mutate(
      { mother, weeks },
      {
        onSuccess: (result) => {
          setPickerFor(null);
          announce(result);
          if (1 === result.created.length && 0 === result.failedCount) {
            adapt(result.created[0].id);
            return;
          }
          if (result.created.length > 1) {
            toast.success(`${result.created.length} plannings de semaine créés — reprenez-les depuis le radar.`);
            afterMultiCreate?.();
          }
        },
      },
    );
  };

  // Chip « + créer » d'UNE semaine manquante (couverture) → crée puis adapte.
  const createOneWeek = (mother: CalendarEntry, week: WeekWindow): void => {
    createWeekChildren.mutate(
      { mother, weeks: [week] },
      {
        onSuccess: (result) => {
          announce(result);
          if (result.created[0]) {
            adapt(result.created[0].id);
          }
        },
      },
    );
  };

  return { pickerFor, setPickerFor, createWeekChildren, pickWeeks, createOneWeek };
}
