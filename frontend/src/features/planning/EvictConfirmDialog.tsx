import { AlertTriangle, Loader2 } from "lucide-react";
import { useId, useRef } from "react";
import { createPortal } from "react-dom";

import { Button } from "@/shared/components/ui/button";
import { useModalA11y } from "@/shared/lib/useModalA11y";

import type { Compromise, MoveViolation } from "./api";
import { CompromiseList } from "./CompromiseList";

/**
 * P2-32 (D6) — la modale qui s'interpose quand on déplace une équipe vers un créneau OCCUPÉ.
 * Elle remplace la confirmation statique : un ESSAI (dry-run) part pendant qu'elle s'ouvre, et
 * elle RESTITUE le verdict du moteur AVANT d'écrire quoi que ce soit. Trois états :
 *  - `checking` : l'essai délibère (« Vérification… ») — aucun bouton de confirmation ;
 *  - `accepted` : le déplacement est légal — on nomme l'occupant, on liste les compromis (ou
 *    « Aucun compromis détecté. »), et [Déplacer et évincer] déclenche le move RÉEL ;
 *  - `refused`  : le moteur refuse — les motifs NOMMÉS, PAS de confirmation, [Fermer] seul.
 *
 * Markup calqué sur `ConfirmDialog` (portail + overlay + focus-trap partagé) : celui-ci ne sait
 * pas rendre un état de chargement ni un refus sans bouton, d'où une modale dédiée à la zone
 * planning plutôt qu'une extension du composant partagé.
 */

export type EvictDialogPhase = "checking" | "accepted" | "refused";

interface EvictConfirmDialogProps {
  open: boolean;
  phase: EvictDialogPhase;
  /** Nom de l'équipe occupant la cible (déjà résolu par la page). */
  occupantName: string;
  /** Compromis du verdict accepté (peut être vide → « Aucun compromis détecté. »). */
  compromises: Compromise[];
  /** Motifs du refus (état `refused`). */
  violations: MoveViolation[];
  /** Le move RÉEL est en vol (confirmation cliquée) : geler la confirmation. */
  busy: boolean;
  onConfirm: () => void;
  onClose: () => void;
}

export function EvictConfirmDialog({ open, phase, occupantName, compromises, violations, busy, onConfirm, onClose }: EvictConfirmDialogProps) {
  const titleId = useId();
  const panelRef = useRef<HTMLDivElement>(null);
  useModalA11y({ ref: panelRef, onClose, active: open });

  if (!open) {
    return null;
  }

  const title = "refused" === phase ? "Déplacement impossible" : "Déplacer vers un créneau occupé ?";

  return createPortal(
    <div className="fixed inset-0 z-[90] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/50" aria-hidden="true" onClick={onClose} />
      <div
        ref={panelRef}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-md flex-col rounded-lg border border-border bg-card p-6 text-card-foreground shadow-xl"
      >
        <h2 id={titleId} className="shrink-0 text-lg font-semibold">
          {title}
        </h2>

        <div className="mt-2 min-h-0 overflow-y-auto text-sm">
          {"checking" === phase ? (
            <div className="flex items-center gap-2 text-muted-foreground" role="status">
              <Loader2 className="size-4 animate-spin" aria-hidden="true" />
              Vérification…
            </div>
          ) : null}

          {"accepted" === phase ? (
            <div className="flex flex-col gap-3">
              <p className="text-muted-foreground">
                Ce créneau est occupé par <span className="font-medium text-foreground">{occupantName}</span>. La déplacer d'ici ? Elle passera dans les séances à replacer.
              </p>
              {compromises.length > 0 ? <CompromiseList compromises={compromises} /> : <p className="text-muted-foreground">Aucun compromis détecté.</p>}
            </div>
          ) : null}

          {"refused" === phase ? (
            <div className="flex flex-col gap-2">
              <div className="flex items-center gap-2 font-medium text-destructive">
                <AlertTriangle className="size-4" aria-hidden="true" />
                <span>Ce déplacement casse une règle du moteur — le créneau ne bougera pas.</span>
              </div>
              <ul className="flex list-disc flex-col gap-1 pl-6 text-muted-foreground">
                {violations.map((violation, index) => (
                  <li key={`${violation.rule}-${index}`}>{violation.message}</li>
                ))}
              </ul>
            </div>
          ) : null}
        </div>

        <div className="mt-6 flex shrink-0 justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            {"refused" === phase ? "Fermer" : "Annuler"}
          </Button>
          {"accepted" === phase ? (
            <Button variant="destructive" disabled={busy} onClick={onConfirm}>
              Déplacer et évincer
            </Button>
          ) : null}
        </div>
      </div>
    </div>,
    document.body,
  );
}
