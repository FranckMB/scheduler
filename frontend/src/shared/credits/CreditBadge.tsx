import { Coins } from "lucide-react";

import { cn } from "@/shared/lib/utils";

import { useCredits } from "./useCredits";

const TOOLTIP = "Une génération, un placement de matchs ou un export consomme 1 crédit — ajuster et consulter sont gratuits.";

/**
 * P1-3 §4bis pt 1 — compteur permanent de crédits dans le shell (Découverte
 * bridée SEULEMENT ; rien du tout en payant/bêta/démo, où `useCredits()` rend
 * null). Passe en AMBRE dès qu'il reste ≤ 5 crédits. La valeur vient du serveur
 * (`entitlements`) — aucun recalcul de règle ici (P2-8).
 */
export function CreditBadge() {
  const credits = useCredits();
  if (null === credits) {
    return null;
  }

  const low = credits.remaining <= 5;
  return (
    <span
      title={TOOLTIP}
      aria-label={`Crédits gratuits restants : ${credits.remaining} sur ${credits.max}. ${TOOLTIP}`}
      className={cn(
        "flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium",
        low ? "border-warning/50 bg-warning/10 text-warning" : "border-border bg-muted text-muted-foreground",
      )}
    >
      <Coins className="size-3.5" aria-hidden="true" />
      Crédits : {credits.remaining}/{credits.max}
    </span>
  );
}
