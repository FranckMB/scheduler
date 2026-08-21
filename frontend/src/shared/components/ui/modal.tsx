import { X } from "lucide-react";
import { type ReactNode, useRef } from "react";
import { createPortal } from "react-dom";

import { useModalA11y } from "@/shared/lib/useModalA11y";
import { cn } from "@/shared/lib/utils";

/** Les quatre paliers de largeur d'une modale — voir `MODAL_WIDTH`. */
export type ModalSize = "sm" | "md" | "lg" | "xl";

/**
 * **La maison unique des largeurs de modale** (P4-107, 3ᵉ tranche). Chaque palier MONTE avec
 * l'écran, puis S'ARRÊTE sur un plafond fixe.
 *
 * Le plafond n'est pas une pudeur : la passe de design `ui-ux-pro-max` n'endosse que des
 * échelles qui terminent sur une largeur fixe (`DON'T Full-width text on large screens`) —
 * une modale qui suivrait indéfiniment le viewport rejouerait sur 1920 px l'anti-pattern
 * qu'on corrige ici sur 448. Tous les plafonds sont atteints dès `lg:` (viewport ≥ 1024 px) :
 * sur un portable comme sur l'écran de référence 1920×1080, c'est la même largeur.
 *
 * ⚠ **Il n'existe plus de prop `className`.** Six appelants s'étaient bricolé leur propre
 * `max-w-…` (quatre valeurs, dont une qui redisait le défaut) — c'est ce qui a produit la
 * dérive. Choisir un `size` est le SEUL geste offert, et `tsc` rougit sur toute récidive :
 * une échappatoire non gardée redeviendrait la dérive en quelques PR.
 *
 * L'échelle est spécifiée — et falsifiée dans les deux sens — par `modal-size.test.tsx`.
 */
export const MODAL_WIDTH: Record<ModalSize, string> = {
  // Confirmation / geste destructif : une phrase, deux boutons. 448 px, constant.
  sm: "max-w-md",
  // Défaut — formulaire de 6 champs au plus. Plafond 576 px : au-delà, une ligne de champs se délie.
  md: "max-w-md sm:max-w-lg lg:max-w-xl",
  // Formulaire long / liste. Plafond 768 px, sous la largeur des pages fiche (832 px).
  lg: "max-w-lg md:max-w-2xl lg:max-w-3xl",
  // Contenu tabulaire / comparaison de plannings. Plafond 1152 px (arbitrage fondateur 2026-08-21).
  xl: "max-w-2xl md:max-w-4xl lg:max-w-5xl xl:max-w-6xl",
};

interface ModalProps {
  label: string;
  title: ReactNode;
  onClose: () => void;
  children: ReactNode;
  /** Palier de largeur — `md` par défaut. Voir `MODAL_WIDTH` pour le choix du palier. */
  size?: ModalSize;
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
export function Modal({ label, title, onClose, children, size = "md" }: ModalProps) {
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
        className={cn("relative flex max-h-[calc(100dvh-2rem)] w-full flex-col rounded-lg border border-border bg-card p-5 text-card-foreground shadow-xl", MODAL_WIDTH[size])}
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
