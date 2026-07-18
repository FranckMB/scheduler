import { useState } from "react";
import { useNavigate } from "react-router-dom";

import { useMe } from "@/features/auth/queries";
import { STATUS_LABELS, type Schedule } from "@/features/planning/api";
import { useWizardStore } from "@/features/wizard/store";
import { Button } from "@/shared/components/ui/button";

import { SeasonSchedulesModal } from "./SeasonSchedulesModal";
import { planRepresentative, visibleSeasonPlans } from "@/features/planning/lib/versions";

import { seasonPlanCounts } from "./seasonPlannings";

interface SeasonPlanBannerProps {
  schedules: Schedule[];
  /** Whether the plan points at a version (state 3) or not yet (state 2). */
  socleValidated: boolean;
  /** Schedules query still in flight — don't flash "aucun planning principal". */
  loading?: boolean;
}

/** Top strip: the season's main plan at a glance + entry points to consult / edit / list all plans. */
export function SeasonPlanBanner({ schedules, socleValidated, loading = false }: SeasonPlanBannerProps) {
  const navigate = useNavigate();
  const { data: me } = useMe();
  const [listOpen, setListOpen] = useState(false);

  // Le planning de la saison TEL QU'IL EST : la version pointée si le gestionnaire
  // en a choisi une, sinon la dernière terminée. Le cockpit s'ouvre dès la première
  // génération (inv. 8/16) alors que le pointeur, lui, attend une validation — ne
  // lire que le pointeur laissait la bannière VIDE en état 2 et après chaque reopen,
  // c'est-à-dire précisément quand le gestionnaire vient regarder son planning.
  const chosen = planRepresentative(visibleSeasonPlans(schedules));
  // Distinct plannings = the season main plan (1) + one per period overlay
  // (versions are navigated inside the planning, not counted here).
  // Both counts from one derivation (finished period plannings only, consistent
  // with what the modal lists).
  const { total: planCount, overlays: overlayCount } = seasonPlanCounts(schedules);

  // Validated (state 3) → consult the plan. Not yet (state 2) → back to the
  // wizard's generation step to finish/validate it.
  const open = () => {
    if (socleValidated) {
      navigate("/planning");
      return;
    }
    // Même racine que la modale : un mode période persisté ferait générer le
    // plan de PÉRIODE à la place du socle — reset avant d'ouvrir la génération.
    useWizardStore.getState().exitPeriodMode();
    useWizardStore.getState().jumpTo("generate");
    navigate("/wizard");
  };

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card p-4">
      <div>
        {/* Le plan porte un NOM (ADR-0002 inv. 12) — l'afficher, pas un libellé générique
            (retour fondateur 2026-07-18 : « Planning de la saison » ici, « Planning
            principal » là = pas UX friendly). */}
        <p className="text-sm font-semibold">{me?.seasonPlan?.name ?? "Planning principal"}</p>
        <p className="text-xs text-muted-foreground">
          {chosen ? (
            <>
              {STATUS_LABELS[chosen.status]}
              {chosen.score !== null ? ` · score ${chosen.score}` : ""}
              {overlayCount > 0 ? ` · ${overlayCount} planning${overlayCount > 1 ? "s" : ""} secondaire${overlayCount > 1 ? "s" : ""}` : ""}
            </>
          ) : loading ? (
            "Chargement…"
          ) : (
            "Aucun planning principal désigné"
          )}
        </p>
      </div>
      <div className="flex flex-wrap gap-2">
        {/* Only "Ouvrir": once inside the planning, the manager decides whether to
            modify (the page has its own reopen/validate controls) — no separate
            Modifier here (user request). */}
        <Button variant="outline" size="sm" onClick={open}>
          Ouvrir
        </Button>
        <Button variant="ghost" size="sm" onClick={() => setListOpen(true)}>
          Tous les plannings ({planCount})
        </Button>
      </div>

      {listOpen ? <SeasonSchedulesModal schedules={schedules} onClose={() => setListOpen(false)} /> : null}
    </div>
  );
}
