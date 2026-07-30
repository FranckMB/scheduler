import { type ReactNode, useId, useRef, useState } from "react";
import { createPortal } from "react-dom";

import { Button } from "@/shared/components/ui/button";
import { useModalA11y } from "@/shared/lib/useModalA11y";

interface ConfirmDialogProps {
  open: boolean;
  title: string;
  description?: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  destructive?: boolean;
  confirmDisabled?: boolean;
  /**
   * Si fournie, le bouton de confirmation reste désactivé tant que l'utilisateur
   * n'a pas tapé cette phrase EXACTE (comparaison trim + insensible à la casse)
   * dans un champ dédié. Garde-fou pour un geste lourd et irréversible.
   */
  confirmPhrase?: string;
  onConfirm: () => void;
  onCancel: () => void;
}

/**
 * Minimal accessible confirmation modal (no dependency): portal + overlay,
 * role="dialog"/aria-modal, Escape + overlay-click cancel, focus the confirm
 * button on open, and a simple Tab focus-trap. Used for destructive actions
 * (delete venue/constraint, reset club).
 */
export function ConfirmDialog({
  open,
  title,
  description,
  confirmLabel = "Confirmer",
  cancelLabel = "Annuler",
  destructive = true,
  confirmDisabled = false,
  confirmPhrase,
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  const titleId = useId();
  const phraseInputId = useId();
  const panelRef = useRef<HTMLDivElement>(null);
  const [phraseInput, setPhraseInput] = useState("");
  const [prevOpen, setPrevOpen] = useState(open);
  // Shared focus-trap + initial focus + focus restoration + Escape (WCAG 2.1.2 / 2.4.3).
  useModalA11y({ ref: panelRef, onClose: onCancel, active: open });

  // Réinitialiser la saisie à chaque bascule ouvert/fermé : une réouverture repart d'un
  // champ vide, jamais de la phrase déjà validée d'une session précédente. Pattern React
  // « ajuster l'état pendant le rendu au changement de prop » (pas d'effet, pas de cascade).
  if (open !== prevOpen) {
    setPrevOpen(open);
    setPhraseInput("");
  }

  if (!open) {
    return null;
  }

  const phraseSatisfied =
    undefined === confirmPhrase || phraseInput.trim().toLowerCase() === confirmPhrase.trim().toLowerCase();
  const confirmBlocked = confirmDisabled || !phraseSatisfied;

  return createPortal(
    <div className="fixed inset-0 z-[90] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/50" aria-hidden="true" onClick={onCancel} />
      <div
        ref={panelRef}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="relative w-full max-w-md rounded-lg border border-border bg-card p-6 text-card-foreground shadow-xl"
      >
        <h2 id={titleId} className="text-lg font-semibold">
          {title}
        </h2>
        {description ? <div className="mt-2 text-sm text-muted-foreground">{description}</div> : null}
        {undefined === confirmPhrase ? null : (
          <div className="mt-4">
            <label htmlFor={phraseInputId} className="block text-sm text-muted-foreground">
              Tapez «&nbsp;{confirmPhrase}&nbsp;» pour confirmer
            </label>
            <input
              id={phraseInputId}
              type="text"
              autoComplete="off"
              value={phraseInput}
              onChange={(event) => setPhraseInput(event.target.value)}
              className="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
            />
          </div>
        )}
        <div className="mt-6 flex justify-end gap-2">
          <Button variant="ghost" onClick={onCancel}>
            {cancelLabel}
          </Button>
          <Button
            variant={destructive ? "destructive" : "default"}
            disabled={confirmBlocked}
            // La friction se ré-arme à chaque tentative : si la confirmation échoue sans
            // fermer le dialogue (500, réseau, JWT expiré), la phrase déjà tapée laisserait
            // le geste destructif à un clic. On vide le champ AVANT de déclencher l'action.
            onClick={() => {
              setPhraseInput("");
              onConfirm();
            }}
          >
            {confirmLabel}
          </Button>
        </div>
      </div>
    </div>,
    document.body,
  );
}
