import type { ReactNode } from "react";
import { Link } from "react-router";

import { useMe } from "@/features/auth/queries";
import { cn } from "@/shared/lib/utils";

import { stepLockReason, wizardDeepLinkHref, type DeepLinkOriginToken } from "./lib/deepLink";
import type { WizardStepId } from "./lib/steps";
import { useWizardStore } from "./store";

/**
 * P2-25 — un lien « va corriger l'objet là où il vit » vers une étape du wizard.
 *
 * Deux régimes, un seul décidé ici : si le mode guidé verrouille l'étape visée
 * (`stepLockReason`), on rend le lien DÉSACTIVÉ avec sa raison VISIBLE — décision fondateur :
 * jamais un saut qui casse l'invariant du mode guidé, jamais un lien qui disparaît sans
 * explication. Sinon un vrai `<Link>` (navigation SPA, URL partageable). Le nouvel onglet est
 * volontairement écarté (brutal sur mobile) : pas de `target=_blank`.
 *
 * `guided` = onboarding (aucune version finie). En mode période `maxIndex` est déjà au max, donc
 * aucune étape n'est verrouillée quel que soit `guided` — pas besoin d'y traiter la période à part.
 */
export function WizardStepLink({
  step,
  params,
  from,
  className,
  children,
}: {
  step: WizardStepId;
  params?: Record<string, string>;
  from?: DeepLinkOriginToken;
  className?: string;
  children: ReactNode;
}) {
  const maxIndex = useWizardStore((s) => s.maxIndex);
  const { data: me } = useMe();
  // `me` en cours de chargement → on N'affiche PAS un lien désactivé par défaut (évite un
  // faux « verrouillé » clignotant) : guidé seulement quand on SAIT qu'aucune version n'est finie.
  const guided = undefined !== me && true !== me.seasonPlan?.hasFinishedVersion;
  const reason = stepLockReason(step, { guided, maxIndex });

  if (null !== reason) {
    return (
      <span className={cn("inline-flex flex-col gap-0.5", className)}>
        <button type="button" disabled aria-disabled="true" className="inline-flex items-center gap-1 text-left opacity-50">
          {children}
        </button>
        <span className="text-xs text-muted-foreground">{reason}</span>
      </span>
    );
  }

  return (
    <Link to={wizardDeepLinkHref(step, params, from)} className={className}>
      {children}
    </Link>
  );
}
