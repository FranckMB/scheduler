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
    // Move focus into the dialog. The panel itself carries tabIndex={-1} so it
    // can receive focus without being a tab stop — a neutral entry point that
    // avoids auto-focusing (and pre-arming) a destructive action button.
    panel?.focus();

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        event.preventDefault();
        onCloseRef.current();
        return;
      }
      if (event.key !== "Tab") {
        return;
      }
      const focusables = panel?.querySelectorAll<HTMLElement>(FOCUSABLE);
      if (!focusables || focusables.length === 0) {
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

    document.addEventListener("keydown", onKeyDown);
    return () => {
      document.removeEventListener("keydown", onKeyDown);
      // WCAG 2.4.3: return focus to the trigger so keyboard/SR users are not
      // dropped at the top of the page when the dialog closes.
      trigger?.focus?.();
    };
  }, [active, ref]);
}
