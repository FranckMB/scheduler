import { useState } from "react";

import { toast } from "@/shared/stores/toastStore";

import type { CalendarEntry } from "../api";
import { useCreateHolidayPeriod, useCreateWeekChildren, type WeekChildrenResult } from "../queries";
import type { WeekWindow } from "./date";

/** Vacance scolaire pas encore matérialisée en période mère (P2-5 E1). */
export interface PendingHoliday {
  schoolHolidayId: string;
  label: string;
  startDate: string;
  endDate: string;
}

/**
 * P2-5 E1 — le flux « découper une période en semaines » PARTAGÉ par le radar et
 * le DayDialog (revue #262 round 3 : il était copié-collé, un fix d'un côté
 * divergeait de l'autre). La DÉCISION d'ouvrir le picker (gating sur bloc-généré,
 * enfants, résolution des queries) reste locale — ses entrées diffèrent entre les
 * deux surfaces — mais la mutation, les toasts et l'auto-adapt à une seule semaine
 * vivent ici, une seule fois.
 *
 * Retour fondateur (2026-07-19) : adapter une VACANCE ne doit RIEN créer tant que
 * les semaines ne sont pas confirmées — la vacance scolaire est déjà l'événement,
 * on ne matérialise sa mère qu'à la confirmation du picker (chemin `pending`).
 */
export function useWeekAdapt(adapt: (entryId: string) => void, afterMultiCreate?: () => void) {
  const [pickerFor, setPickerFor] = useState<CalendarEntry | null>(null);
  // Vacance PAS encore créée : le picker s'ouvre sur cette référence SANS
  // matérialiser la mère — annuler ne doit rien laisser. La mère naît à la
  // confirmation (pickWeeksPending / adaptWholePending).
  const [pendingHoliday, setPendingHoliday] = useState<PendingHoliday | null>(null);
  const createWeekChildren = useCreateWeekChildren();
  const createHoliday = useCreateHolidayPeriod();

  const announce = ({ created, failedCount }: WeekChildrenResult): void => {
    if (failedCount > 0) {
      toast.error(`${failedCount} semaine${failedCount > 1 ? "s" : ""} n'a pas pu être créée — réessayez depuis la carte de couverture.`);
    }
    if (0 === created.length && 0 === failedCount) {
      toast.info("Ces semaines étaient déjà découpées.");
    }
  };

  // Suite commune une fois les semaines créées (mère matérialisée OU fraîchement
  // née) : 1 seule sans échec → adapt direct ; plusieurs → toast + retour cockpit
  // (les cartes de couverture prennent le relais).
  const finishChildren = (result: WeekChildrenResult): void => {
    announce(result);
    if (1 === result.created.length && 0 === result.failedCount) {
      adapt(result.created[0].id);
      return;
    }
    if (result.created.length > 1) {
      toast.success(`${result.created.length} plannings de semaine créés — reprenez-les depuis le radar.`);
      afterMultiCreate?.();
    }
  };

  // Semaines cochées d'une mère DÉJÀ matérialisée → création des plans.
  const pickWeeks = (mother: CalendarEntry, weeks: WeekWindow[]): void => {
    createWeekChildren.mutate(
      { mother, weeks },
      {
        onSuccess: (result) => {
          setPickerFor(null);
          finishChildren(result);
        },
      },
    );
  };

  const openPendingPicker = (holiday: PendingHoliday): void => setPendingHoliday(holiday);

  // Confirmation du picker pour une vacance PAS encore créée : la mère naît ICI,
  // puis ses semaines. mutateAsync (pas d'onSuccess portée) : navigation/toasts
  // doivent partir même si la modale se referme pendant le POST. Erreur relevée
  // par le filet global des mutations (queryClient.ts).
  const pickWeeksPending = async (holiday: PendingHoliday, weeks: WeekWindow[]): Promise<void> => {
    try {
      const mother = await createHoliday.mutateAsync(holiday);
      setPendingHoliday(null);
      createWeekChildren.mutate({ mother, weeks }, { onSuccess: finishChildren });
    } catch {
      /* relevé par le filet global des mutations */
    }
  };

  // Chemin « d'un bloc » d'une vacance PAS encore créée : mère née puis adapt direct.
  const adaptWholePending = async (holiday: PendingHoliday): Promise<void> => {
    try {
      const mother = await createHoliday.mutateAsync(holiday);
      setPendingHoliday(null);
      adapt(mother.id);
    } catch {
      /* relevé par le filet global des mutations */
    }
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

  return {
    pickerFor,
    setPickerFor,
    pendingHoliday,
    setPendingHoliday,
    openPendingPicker,
    createWeekChildren,
    createHoliday,
    pickWeeks,
    pickWeeksPending,
    adaptWholePending,
    createOneWeek,
  };
}
