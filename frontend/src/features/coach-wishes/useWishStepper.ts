import { useCallback, useMemo, useState } from "react";

/** Nature d'une étape du parcours de doléances (lot E, P2-24). */
export type WishStepKind = "intro" | "team" | "recap";

export interface WishStep {
  kind: WishStepKind;
  /** Identifiant d'équipe — étapes « team » uniquement. */
  teamId?: string;
  /** Position 0-based parmi les équipes — étapes « team » uniquement. */
  teamIndex?: number;
}

export interface WishStepper {
  /** intro · une étape par équipe · récap (l'écran de validation). */
  steps: WishStep[];
  index: number;
  current: WishStep;
  isFirst: boolean;
  isRecap: boolean;
  /** Vrai quand « Suivant »/« Précédent » ramènent au récap (édition depuis le récap). */
  returningToRecap: boolean;
  isVisited: (i: number) => boolean;
  /** Une étape est atteignable au clic si elle a déjà été visitée. */
  canGoTo: (i: number) => boolean;
  next: () => void;
  prev: () => void;
  goTo: (i: number) => void;
  /** Saute à l'étape d'une équipe et arme le retour direct au récap. */
  editTeam: (teamId: string) => void;
}

/**
 * État PUR du parcours en étapes — aucune donnée de section, seulement l'ordre des
 * écrans, les visites et la navigation. L'envoi et le dirty-tracking vivent dans la page.
 *
 * `initialIndex` restaure l'étape courante d'un brouillon (filet sessionStorage) : les
 * étapes 0..index sont alors marquées visitées (le coach les a traversées).
 */
export function useWishStepper(teamIds: string[], initialIndex = 0): WishStepper {
  const steps = useMemo<WishStep[]>(() => {
    const list: WishStep[] = [{ kind: "intro" }];
    teamIds.forEach((teamId, teamIndex) => list.push({ kind: "team", teamId, teamIndex }));
    list.push({ kind: "recap" });
    return list;
  }, [teamIds]);

  const recapIndex = steps.length - 1;
  const start = Math.max(0, Math.min(initialIndex, recapIndex));
  const [index, setIndex] = useState(start);
  const [visited, setVisited] = useState<Set<number>>(() => new Set(Array.from({ length: start + 1 }, (_, i) => i)));
  const [returnTo, setReturnTo] = useState<number | null>(null);

  const arrive = useCallback((i: number) => {
    setIndex(i);
    setVisited((prev) => (prev.has(i) ? prev : new Set(prev).add(i)));
  }, []);

  const next = useCallback(() => {
    if (null !== returnTo) {
      const target = returnTo;
      setReturnTo(null);
      arrive(target);
      return;
    }
    if (index < recapIndex) {
      arrive(index + 1);
    }
  }, [arrive, index, recapIndex, returnTo]);

  const prev = useCallback(() => {
    if (null !== returnTo) {
      const target = returnTo;
      setReturnTo(null);
      arrive(target);
      return;
    }
    if (index > 0) {
      arrive(index - 1);
    }
  }, [arrive, index, returnTo]);

  const goTo = useCallback(
    (i: number) => {
      if (i >= 0 && i < steps.length && visited.has(i)) {
        setReturnTo(null);
        arrive(i);
      }
    },
    [arrive, steps.length, visited],
  );

  const editTeam = useCallback(
    (teamId: string) => {
      const i = steps.findIndex((s) => "team" === s.kind && s.teamId === teamId);
      if (i >= 0) {
        setReturnTo(recapIndex);
        arrive(i);
      }
    },
    [arrive, recapIndex, steps],
  );

  const isVisited = useCallback((i: number) => visited.has(i), [visited]);
  const canGoTo = isVisited;

  return {
    steps,
    index,
    current: steps[index],
    isFirst: 0 === index,
    isRecap: index === recapIndex,
    returningToRecap: null !== returnTo,
    isVisited,
    canGoTo,
    next,
    prev,
    goTo,
    editTeam,
  };
}
