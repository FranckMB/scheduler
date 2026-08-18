import { useState } from "react";

import { useWorkingSeason } from "@/features/auth/queries";
import type { Schedule } from "@/features/planning/api";
import { IN_FLIGHT_STATUSES } from "@/features/planning/lib/scheduleStatus";
import { useDeleteSchedule, useSchedules } from "@/features/planning/queries";
import { toast } from "@/shared/stores/toastStore";

import type { CalendarEntry, SchedulePlan } from "../api";
import { WindowAlreadyPlannedError } from "../api";
import { useCreateHolidayPeriod, useCreatePeriodPlan, useCreateWeekChildren, useSchedulePlans, type WeekChildrenResult } from "../queries";
import { periodWeeksToAdjust, todayISO, type WeekWindow } from "./date";

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
 * P2-36 — LA décision « comment ouvrir l'ajustement d'une période multi-semaines »,
 * maison UNIQUE. Le radar et le DayDialog la dupliquaient avec des entrées différentes
 * (`RadarPanel` gardait `!schedulesUnresolved`, `DayDialog` `schedulesResolved &&
 * planResolved && childrenResolved`) : corriger un seul côté garantissait un écart. Le
 * défaut qu'elle ferme : quand la condition tombait, l'écran BASCULAIT en bloc SANS RIEN
 * DIRE (le serveur, lui, refuse le découpage forcé par un 422 explicite).
 *
 * ⚠ CHAQUE cause est NOMMÉE — un « ça part en bloc » générique recréerait le défaut avec
 * plus de mots. Quatre causes distinctes, deux issues :
 *  - `single-week` / `already-split` → aucun choix à offrir : bloc direct (comportement
 *    conservé — une seule semaine calendaire, ou une mère déjà découpée que sa carte de
 *    couverture gouverne) ;
 *  - `loading` → plans/plannings/enfants pas encore résolus : le picker s'OUVRE et le dit,
 *    au lieu de partir en bloc en silence (le repli « ne jamais cocher sans savoir » reste,
 *    seul le silence disparaît) ;
 *  - `block-generated` → un plan de bloc porte déjà des versions : le picker s'OUVRE et
 *    NOMME le fait, garde « Continuer d'un bloc » et propose la découpe destructive.
 */
export type WeekAdaptDecision =
  | { kind: "single-week" }
  | { kind: "already-split" }
  | { kind: "loading" }
  | { kind: "block-generated" }
  | { kind: "weeks" };

export interface WeekAdaptInput {
  /** La période couvre-t-elle PLUSIEURS semaines calendaires à ajuster (sinon : rien à choisir) ? */
  multiWeek: boolean;
  /** La mère porte-t-elle déjà des semaines-enfants (sa carte de couverture prend le relais) ? */
  alreadySplit: boolean;
  /** Plans + plannings + enfants tous résolus ? Faux ⇒ on ne PEUT pas décider bloc/semaines. */
  resolved: boolean;
  /** Le plan « bloc » de la période porte-t-il déjà ≥ 1 version ? */
  blockGenerated: boolean;
}

export function decideWeekAdapt({ multiWeek, alreadySplit, resolved, blockGenerated }: WeekAdaptInput): WeekAdaptDecision {
  if (!multiWeek) {
    return { kind: "single-week" };
  }
  if (alreadySplit) {
    return { kind: "already-split" };
  }
  if (!resolved) {
    return { kind: "loading" };
  }
  if (blockGenerated) {
    return { kind: "block-generated" };
  }
  return { kind: "weeks" };
}

/** Les états VISIBLES du picker. `null` ⇒ la décision ne l'ouvre pas (bloc direct). */
export type WeekPickerState = "weeks" | "loading" | "block";
export function pickerStateOf(decision: WeekAdaptDecision): WeekPickerState | null {
  switch (decision.kind) {
    case "weeks":
      return "weeks";
    case "loading":
      return "loading";
    case "block-generated":
      return "block";
    case "single-week":
    case "already-split":
      return null;
  }
}

/** Ce que le picker doit dire de l'état « bloc déjà généré » (lu du plan + de ses versions). */
export interface BlockVersionsInfo {
  versionCount: number;
  /** Le plan de bloc porte une version CHOISIE/validée : la découpe destructive n'est PAS offerte. */
  validated: boolean;
  /** Une version est EN GÉNÉRATION : la découpe est désactivée tant qu'elle vole. */
  generationInFlight: boolean;
}

/**
 * P2-5 E1 — le flux « découper une période en semaines » PARTAGÉ par le radar et
 * le DayDialog (revue #262 round 3 : il était copié-collé, un fix d'un côté
 * divergeait de l'autre). La DÉCISION d'ouvrir le picker (P2-36) et la mutation,
 * les toasts, l'auto-adapt à une seule semaine et la découpe destructive vivent ici,
 * une seule fois. Les deux surfaces ne passent plus que l'entrée (+ leur connaissance
 * des semaines-enfants, qu'elles seules ont) — la logique, elle, ne se dédouble plus.
 *
 * Retour fondateur (2026-07-19) : adapter une VACANCE ne doit RIEN créer tant que
 * les semaines ne sont pas confirmées — la vacance scolaire est déjà l'événement,
 * on ne matérialise sa mère qu'à la confirmation du picker (chemin `pending`). Une
 * fois les semaines créées, le wizard s'ouvre directement sur la 1ʳᵉ (on travaille
 * tout de suite ; les autres semaines restent au radar / « tous les plannings »).
 */
export function useWeekAdapt(adapt: (entryId: string) => void, childrenResolved = true) {
  const [pickerFor, setPickerFor] = useState<CalendarEntry | null>(null);
  // Vacance PAS encore créée : le picker s'ouvre sur cette référence SANS
  // matérialiser la mère — annuler ne doit rien laisser. La mère naît à la
  // confirmation (pickWeeksPending / adaptWholePending).
  const [pendingHoliday, setPendingHoliday] = useState<PendingHoliday | null>(null);
  const createWeekChildren = useCreateWeekChildren();
  const createHoliday = useCreateHolidayPeriod();
  const createPeriodPlan = useCreatePeriodPlan();
  // P2-36 — les lectures dont dépend la décision d'ouverture ET l'état « bloc déjà généré » :
  // sourcées ICI (react-query dédoublonne avec les mêmes lectures des surfaces) pour que
  // celles-ci ne passent plus que l'entrée. `undefined` = pas encore résolu → état « chargement ».
  const plansQuery = useSchedulePlans();
  const plans = plansQuery.data;
  const schedulesQuery = useSchedules();
  const schedules = schedulesQuery.data;
  const workingSeason = useWorkingSeason();
  const deleteSchedule = useDeleteSchedule();
  const [blockDeleting, setBlockDeleting] = useState(false);
  const [blockDeleteFailed, setBlockDeleteFailed] = useState(false);

  const planForEntry = (entryId: string): SchedulePlan | null => (plans ?? []).find((p) => p.calendarEntryId === entryId) ?? null;
  const versionsOf = (planId: string | null): Schedule[] => (null === planId ? [] : (schedules ?? []).filter((s) => s.schedulePlanId === planId));
  const decisionFor = (entry: CalendarEntry, alreadySplit: boolean): WeekAdaptDecision => {
    const multiWeek = null !== workingSeason && periodWeeksToAdjust(entry.startDate, entry.endDate, workingSeason, entry.periodType, todayISO()).length > 1;
    const resolved = undefined !== plans && undefined !== schedules && childrenResolved;
    const plan = planForEntry(entry.id);
    const blockGenerated = null !== plan && versionsOf(plan.id).length > 0;
    return decideWeekAdapt({ multiWeek, alreadySplit, resolved, blockGenerated });
  };
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

  // P2-36 — LE geste « Adapter » d'une période multi-semaines : les deux surfaces l'appellent
  // avec la seule entrée (+ leur savoir « déjà découpée »). `single-week`/`already-split` →
  // bloc direct ; les trois autres OUVRENT le picker (qui NOMME loading / block / weeks) —
  // fini la bascule silencieuse.
  const requestAdapt = (entry: CalendarEntry, { alreadySplit }: { alreadySplit: boolean }): void => {
    const decision = decisionFor(entry, alreadySplit);
    if (null !== pickerStateOf(decision)) {
      setWindowConflict(null);
      setBlockDeleteFailed(false);
      setPickerFor(entry);
      return;
    }
    void adaptBlock(entry.id);
  };

  // État courant du picker ouvert, RECALCULÉ à chaque rendu depuis les lectures vives : un
  // « chargement » bascule tout seul en « choix des semaines » (ou « bloc ») dès que les
  // données arrivent, et une découpe destructive réussie le fait passer de « bloc » à
  // « semaines » sans un clic de plus (les versions supprimées → `blockGenerated` retombe).
  const pickerDecision = null === pickerFor ? null : decisionFor(pickerFor, false);
  const pickerState: WeekPickerState = null === pickerDecision ? "weeks" : (pickerStateOf(pickerDecision) ?? "weeks");
  const pickerPlan = null === pickerFor ? null : planForEntry(pickerFor.id);
  const pickerVersions = versionsOf(pickerPlan?.id ?? null);
  const blockInfo: BlockVersionsInfo = {
    versionCount: pickerVersions.length,
    validated: null !== (pickerPlan?.chosenScheduleId ?? null),
    generationInFlight: pickerVersions.some((v) => IN_FLIGHT_STATUSES.includes(v.status)),
  };

  // La chaîne « supprimer les versions et découper en semaines » (décision fondateur) —
  // n'orchestre QUE des gestes existants : DELETE /api/schedules/{id} version par version.
  // Jamais le plan (le serveur l'emporte au découpage) ni l'entrée (elle doit survivre pour
  // être découpée). Échec PARTIEL → on reste dans l'état bloqué avec le compte à jour (pas de
  // fausse atomicité — même doctrine que le compteur d'échecs de la création de semaines).
  // Gardes défensives (le bouton les applique déjà côté UI, on ne s'y fie pas) : jamais sur un
  // bloc VALIDÉ (chaîne non atomique), jamais pendant une génération.
  const deleteBlockVersionsAndSplit = async (): Promise<void> => {
    if (null === pickerFor || null === pickerPlan || null !== pickerPlan.chosenScheduleId) {
      return;
    }
    const versions = versionsOf(pickerPlan.id);
    if (versions.some((v) => IN_FLIGHT_STATUSES.includes(v.status))) {
      return;
    }
    setBlockDeleteFailed(false);
    setBlockDeleting(true);
    let failed = 0;
    for (const version of versions) {
      try {
        await deleteSchedule.mutateAsync(version.id);
      } catch {
        failed += 1;
      }
    }
    setBlockDeleting(false);
    if (failed > 0) {
      setBlockDeleteFailed(true);
      toast.error(`${failed} version${failed > 1 ? "s" : ""} n'a pas pu être supprimée — réessayez.`);
    }
    // Succès total : useDeleteSchedule a invalidé « schedules » → le picker rebascule seul en
    // « choix des semaines » (pickerState réactif). Rien d'autre à faire (pas de navigation).
  };

  return {
    requestAdapt,
    pickerState,
    blockInfo,
    blockDeleting,
    blockDeleteFailed,
    deleteBlockVersionsAndSplit,
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
