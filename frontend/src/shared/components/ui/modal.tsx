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

/** Minimal portal modal: overlay + Escape/overlay-click close + a titled panel. Shared by the cockpit dialogs. */
export function Modal({ label, title, onClose, children, className }: ModalProps) {
  const panelRef = useRef<HTMLDivElement>(null);
  // Focus-trap + initial focus + focus restoration + Escape (WCAG 2.1.2 / 2.4.3).
  useModalA11y({ ref: panelRef, onClose });

  return createPortal(
    <div className="fixed inset-0 z-[90] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/50" aria-hidden="true" onClick={onClose} />
      <div ref={panelRef} tabIndex={-1} role="dialog" aria-modal="true" aria-label={label} className={cn("relative w-full max-w-md rounded-lg border border-border bg-card p-5 text-card-foreground shadow-xl", className)}>
        <div className="flex items-center justify-between">
          <h2 className="text-base font-semibold">{title}</h2>
          <button type="button" aria-label="Fermer" className="rounded p-1 text-muted-foreground hover:text-foreground" onClick={onClose}>
            <X className="size-4" />
          </button>
        </div>
        {children}
      </div>
    </div>,
    document.body,
  );
}
