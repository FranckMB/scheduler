import type { ReactNode } from "react";

import type { PeriodAnchor } from "@/features/cockpit/queries";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";

/**
 * La PORTE de l'ancre de période : on n'obtient un `planId` qu'en ayant traité
 * `loading` ET `failed`.
 *
 * Sans elle, chaque écran re-décide s'il gère l'échec — et l'oublie. C'est
 * arrivé quatre fois (P4-20), y compris en tentant de corriger la ligne : un
 * drapeau optionnel ajouté au tuple laisse les autres appelants intacts. Ici,
 * il n'existe pas d'autre chemin vers l'ancre : oublier l'échec demanderait de
 * contourner la porte, ce qui se voit en revue.
 *
 * `base` (hors mode période) n'est pas rendu : ces écrans ne s'affichent qu'en
 * mode période, où l'ancre est un plan ou rien. Un `base` qui arriverait ici
 * signalerait un appelant qui passe le mauvais `calendarEntryId` — traité comme
 * « pas d'ancre », donc pas d'écriture possible.
 */
export function PeriodAnchorGate({
  anchor,
  loadingLabel,
  errorLabel,
  children,
}: {
  anchor: PeriodAnchor;
  loadingLabel: string;
  errorLabel: string;
  children: (planId: string) => ReactNode;
}) {
  if ("failed" === anchor.state) {
    return <LoadErrorHint onRetry={anchor.retry}>{errorLabel}</LoadErrorHint>;
  }
  // Réglé SANS plan : ce n'est pas un chargement. L'annoncer comme tel donnait un
  // spinner éternel ; on dit l'état réel, qui a son propre geste (Adapter la période).
  if ("absent" === anchor.state) {
    return (
      <p className="text-xs text-muted-foreground">
        Cette période n’a pas encore d’espace de travail — utilisez « Adapter » sur la période pour en créer un.
      </p>
    );
  }
  // `base` ne devrait pas arriver ici (ces écrans ne vivent qu'en mode période) :
  // c'est un appelant qui passe un `calendarEntryId` nul — état atteignable après
  // une rehydratation partielle du store. Le rendre comme un chargement donnait
  // un spinner qui ne finit jamais ; on nomme l'anomalie et on offre la sortie.
  if ("base" === anchor.state) {
    return (
      <p className="text-xs text-muted-foreground">
        Aucune période sélectionnée — revenez au calendrier et rouvrez la période.
      </p>
    );
  }
  if ("period" !== anchor.state) {
    return <p className="text-xs text-muted-foreground">{loadingLabel}</p>;
  }

  return <>{children(anchor.planId)}</>;
}
