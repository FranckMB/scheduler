import { useState } from "react";

import { toast } from "@/shared/stores/toastStore";

import type { CalendarEntry } from "../api";
import { WindowAlreadyPlannedError } from "../api";
import { useCreateHolidayPeriod, useCreatePeriodPlan, useCreateWeekChildren, type WeekChildrenResult } from "../queries";
import type { WeekWindow } from "./date";

/** Le refus « une seule planification par fenêtre » (P2-38), tel que l'affiche le geste. */
export interface WindowConflict {
  message: string;
  entryId: string;
}

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
 * on ne matérialise sa mère qu'à la confirmation du picker (chemin `pending`). Une
 * fois les semaines créées, le wizard s'ouvre directement sur la 1ʳᵉ (on travaille
 * tout de suite ; les autres semaines restent au radar / « tous les plannings »).
 */
export function useWeekAdapt(adapt: (entryId: string) => void) {
  const [pickerFor, setPickerFor] = useState<CalendarEntry | null>(null);
  // Vacance PAS encore créée : le picker s'ouvre sur cette référence SANS
  // matérialiser la mère — annuler ne doit rien laisser. La mère naît à la
  // confirmation (pickWeeksPending / adaptWholePending).
  const [pendingHoliday, setPendingHoliday] = useState<PendingHoliday | null>(null);
  const createWeekChildren = useCreateWeekChildren();
  const createHoliday = useCreateHolidayPeriod();
  const createPeriodPlan = useCreatePeriodPlan();
  // P2-38 — le refus de chevauchement affiché à l'endroit du geste (picker ouvert → dans le
  // picker ; sinon → dans l'encart). Un seul état : les deux cas s'excluent (un refus de picker
  // GARDE le picker ouvert ; adaptBlock/createOneWeek se jouent sans picker). Réinitialisé au
  // début de chaque tentative pour ne jamais coller un refus périmé sur un nouveau geste.
  const [windowConflict, setWindowConflict] = useState<WindowConflict | null>(null);
  const resetWindowConflict = (): void => setWindowConflict(null);
  const noteWindowConflict = (error: unknown): void => {
    if (error instanceof WindowAlreadyPlannedError) {
      setWindowConflict({ message: error.message, entryId: error.conflictingEntryId });
    }
  };

  // LE GESTE « Adapter » un BLOC (mère entière / fermeture) — ADR-0002 amendé
  // 2026-07-24 : la période n'a plus de plan à sa matérialisation, il naît ICI,
  // AVANT startPeriodMode (sinon usePeriodAnchor attendrait à l'infini). Les
  // SEMAINES, elles, naissent avec leur plan : leur adapt() reste direct.
  // Idempotent côté serveur ; erreur (ex. 422 période découpée sur données
  // périmées) relevée par le filet global des mutations — on ne navigue pas.
  const adaptBlock = async (entryId: string): Promise<void> => {
    setWindowConflict(null);
    try {
      await createPeriodPlan.mutateAsync(entryId);
      adapt(entryId);
    } catch (error) {
      // Refus de chevauchement (P2-38) → affiché comme proposition ; tout autre échec → filet
      // global des mutations (queryClient.ts).
      noteWindowConflict(error);
    }
  };

  const announce = ({ created, failedCount }: WeekChildrenResult): void => {
    if (failedCount > 0) {
      toast.error(`${failedCount} semaine${failedCount > 1 ? "s" : ""} n'a pas pu être créée — réessayez depuis la carte de couverture.`);
    }
    if (0 === created.length && 0 === failedCount) {
      toast.info("Ces semaines étaient déjà découpées.");
    }
  };

  // Suite commune une fois les semaines créées (mère matérialisée OU fraîchement
  // née) : on ouvre le wizard sur la 1ʳᵉ semaine créée (retour fondateur
  // 2026-07-19). ≥2 créées → toast qui rappelle où retrouver les autres. En cas
  // d'ÉCHEC PARTIEL (des semaines n'ont pas été créées), on NE navigue PAS : le
  // gestionnaire doit lire le toast d'erreur et relancer les manquantes depuis la
  // carte de couverture (revue B1 : naviguer emporterait l'avertissement).
  const finishChildren = (result: WeekChildrenResult): void => {
    announce(result);
    if (0 === result.created.length || result.failedCount > 0) {
      return;
    }
    if (result.created.length > 1) {
      toast.success(`${result.created.length} plannings de semaine créés — le 1ᵉʳ est ouvert, les autres au radar.`);
    }
    adapt(result.created[0].id);
  };

  // Semaines cochées d'une mère DÉJÀ matérialisée → création des plans.
  const pickWeeks = (mother: CalendarEntry, weeks: WeekWindow[]): void => {
    setWindowConflict(null);
    createWeekChildren.mutate(
      { mother, weeks },
      {
        onSuccess: (result) => {
          setPickerFor(null);
          finishChildren(result);
        },
        // Une semaine gouvernée par un autre plan (P2-38) : le picker RESTE ouvert et affiche
        // le refus ; les autres échecs vont au filet global.
        onError: noteWindowConflict,
      },
    );
  };

  const openPendingPicker = (holiday: PendingHoliday): void => setPendingHoliday(holiday);

  // Confirmation du picker pour une vacance PAS encore créée : la mère naît ICI,
  // puis ses semaines. mutateAsync (pas d'onSuccess portée) : navigation/toasts
  // doivent partir même si la modale se referme pendant le POST. Erreur relevée
  // par le filet global des mutations (queryClient.ts).
  const pickWeeksPending = async (holiday: PendingHoliday, weeks: WeekWindow[]): Promise<void> => {
    setWindowConflict(null);
    try {
      const mother = await createHoliday.mutateAsync(holiday);
      setPendingHoliday(null);
      createWeekChildren.mutate({ mother, weeks }, { onSuccess: finishChildren, onError: noteWindowConflict });
    } catch (error) {
      // La création de la mère elle-même ne heurte pas la garde (entrée racine, sans plan) ;
      // seul le lot de semaines peut. Par sûreté, un refus typé est aussi capté ici.
      noteWindowConflict(error);
    }
  };

  // Chemin « d'un bloc » d'une vacance PAS encore créée : mère née SANS plan
  // (amendement 2026-07-24), puis le geste adaptBlock le crée et ouvre le wizard.
  const adaptWholePending = async (holiday: PendingHoliday): Promise<void> => {
    try {
      const mother = await createHoliday.mutateAsync(holiday);
      setPendingHoliday(null);
      await adaptBlock(mother.id);
    } catch {
      /* relevé par le filet global des mutations */
    }
  };

  // Chip « + créer » d'UNE semaine manquante (couverture) → crée puis adapte.
  const createOneWeek = (mother: CalendarEntry, week: WeekWindow): void => {
    setWindowConflict(null);
    createWeekChildren.mutate(
      { mother, weeks: [week] },
      {
        onSuccess: (result) => {
          announce(result);
          if (result.created[0]) {
            adapt(result.created[0].id);
          }
        },
        // Cette semaine recoupe un autre plan (P2-38) → refus affiché dans l'encart.
        onError: noteWindowConflict,
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
    createPeriodPlan,
    adaptBlock,
    pickWeeks,
    pickWeeksPending,
    adaptWholePending,
    createOneWeek,
    windowConflict,
    resetWindowConflict,
  };
}
