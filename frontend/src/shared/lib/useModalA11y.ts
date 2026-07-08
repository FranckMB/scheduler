import { type RefObject, useEffect, useRef } from "react";

const FOCUSABLE = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

interface ModalA11yOptions {
  /** The dialog panel element (role="dialog"), carrying tabIndex={-1}. */
  ref: RefObject<HTMLElement | null>;
  /** Called on Escape (and, by the caller, on overlay click). */
  onClose: () => void;
  /** Skip everything when the modal is closed (default true). */
  active?: boolean;
}

/**
 * Shared modal accessibility (WCAG 2.1.2 no keyboard trap / 2.4.3 focus order):
 * on open, move focus into the panel; keep Tab cycling inside it; close on
 * Escape; and RESTORE focus to the element that opened the modal on close. One
 * mechanism for every dialog (Modal + ConfirmDialog) so a11y is uniform — the
 * audit's A11Y-03 / FRT-12/13 / UXC-02 came from per-modal, divergent handling.
 */
export function useModalA11y({ ref, onClose, active = true }: ModalA11yOptions): void {
  // Read onClose through a ref so the trap only re-binds when open/panel change,
  // not on every parent render passing a fresh inline arrow. Update in an effect
  // (never mutate a ref during render).
  const onCloseRef = useRef(onClose);
  useEffect(() => {
    onCloseRef.current = onClose;
  }, [onClose]);

  useEffect(() => {
    if (!active) {
      return;
    }

    const trigger = document.activeElement as HTMLElement | null;
    const panel = ref.current;
    if (!panel) {
      return;
    }
    // Move focus into the dialog. The panel itself carries tabIndex={-1} so it
    // can receive focus without being a tab stop — a neutral entry point that
    // avoids auto-focusing (and pre-arming) a destructive action button.
    panel.focus();

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        event.preventDefault();
        onCloseRef.current();
        return;
      }
      if (event.key !== "Tab") {
        return;
      }
      const focusables = panel.querySelectorAll<HTMLElement>(FOCUSABLE);
      if (focusables.length === 0) {
        return;
      }
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      const activeEl = document.activeElement;
      if (event.shiftKey && (activeEl === first || activeEl === panel)) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && activeEl === last) {
        event.preventDefault();
        first.focus();
      }
    };

    // Listen on the PANEL, not document: a ConfirmDialog nested inside a Modal is
    // a separate portal subtree, so Escape/Tab only reach the dialog that holds
    // focus — pressing Escape on the inner confirm no longer also closes the outer
    // modal (both are body-level portals; a document listener fired both).
    panel.addEventListener("keydown", onKeyDown);
    return () => {
      panel.removeEventListener("keydown", onKeyDown);
      // WCAG 2.4.3: return focus to the trigger. If the trigger was removed by the
      // action this dialog confirmed (e.g. the row's delete button), fall back to
      // the next still-open dialog so focus is not dropped to <body>.
      if (trigger?.isConnected) {
        trigger.focus();
        return;
      }
      const remaining = [...document.querySelectorAll<HTMLElement>('[role="dialog"]')].filter((d) => d !== panel);
      remaining[remaining.length - 1]?.focus();
    };
  }, [active, ref]);
}
