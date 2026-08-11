import { X } from "lucide-react";
import { type ReactNode, useRef } from "react";
import { createPortal } from "react-dom";

import { useModalA11y } from "@/shared/lib/useModalA11y";
import { cn } from "@/shared/lib/utils";

interface ModalProps {
  label: string;
  title: ReactNode;
  onClose: () => void;
  children: ReactNode;
  className?: string;
}

/**
 * Minimal portal modal: overlay + Escape/overlay-click close + a titled panel. Shared by the cockpit dialogs.
 *
 * ⚠ **Le panneau est BORNÉ en hauteur et son contenu défile — ne retirez pas ces classes.**
 * Sans elles, un panneau plus haut que l'écran débordait **en haut ET en bas** (le conteneur
 * centre en `items-center`) et rien ne permettait d'atteindre le contenu coupé : le seul
 * recours était de dézoomer le navigateur. Constaté sur la modale d'actions superadmin, sur
 * PC (retour fondateur 2026-08-11). C'est aussi un manquement WCAG 1.4.10 (reflow) — un
 * contenu hors viewport sans moyen de le faire défiler n'est pas atteignable.
 *
 * ⚑ Le défaut était visible avant d'être signalé : trois écrans s'étaient bricolé leur
 * propre rustine (`max-h-[60vh]`, `max-h-[24rem]`, `max-h-[55vh]` — trois valeurs
 * arbitraires). Quand chaque appelant rafistole dans son coin, c'est que le comportement
 * manque en amont ; elles ont été retirées avec ce correctif.
 *
 * `dvh` et non `vh` : sur mobile `vh` ignore la barre d'adresse et reproduit le débordement.
 *
 * L'en-tête reste visible (`shrink-0`), seul le contenu défile. `min-h-0` est obligatoire —
 * un enfant flex refuse de rétrécir sous sa taille de contenu sans lui, et `overflow-y-auto`
 * n'aurait alors rien à faire défiler.
 */
export function Modal({ label, title, onClose, children, className }: ModalProps) {
  const panelRef = useRef<HTMLDivElement>(null);
  // Focus-trap + initial focus + focus restoration + Escape (WCAG 2.1.2 / 2.4.3).
  useModalA11y({ ref: panelRef, onClose });

  return createPortal(
    <div className="fixed inset-0 z-[90] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/50" aria-hidden="true" onClick={onClose} />
      <div
        ref={panelRef}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-label={label}
        className={cn("relative flex max-h-[calc(100dvh-2rem)] w-full max-w-md flex-col rounded-lg border border-border bg-card p-5 text-card-foreground shadow-xl", className)}
      >
        <div className="flex shrink-0 items-center justify-between">
          <h2 className="text-base font-semibold">{title}</h2>
          <button type="button" aria-label="Fermer" className="rounded p-1 text-muted-foreground hover:text-foreground" onClick={onClose}>
            <X className="size-4" />
          </button>
        </div>
        <div className="min-h-0 overflow-y-auto">{children}</div>
      </div>
    </div>,
    document.body,
  );
}
