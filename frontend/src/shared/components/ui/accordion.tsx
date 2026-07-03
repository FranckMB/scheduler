import { ChevronDown } from "lucide-react";
import { type ReactNode, useId, useState } from "react";

import { cn } from "@/shared/lib/utils";

interface AccordionSectionProps {
  title: ReactNode;
  /** Open on first render. Uncontrolled thereafter. */
  defaultOpen?: boolean;
  children: ReactNode;
  className?: string;
}

/**
 * Reusable collapsible section: a header button toggles the body.
 * aria-expanded + aria-controls wire the header to its region.
 */
export function AccordionSection({ title, defaultOpen = false, children, className }: AccordionSectionProps) {
  const [open, setOpen] = useState(defaultOpen);
  const bodyId = useId();

  return (
    <div className={cn("rounded-lg border border-border", className)}>
      <button
        type="button"
        aria-expanded={open}
        aria-controls={open ? bodyId : undefined}
        onClick={() => setOpen((v) => !v)}
        className="flex w-full items-center justify-between gap-2 rounded-lg px-4 py-3 text-left text-sm font-semibold transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
      >
        {title}
        <ChevronDown className={cn("size-4 shrink-0 text-muted-foreground transition-transform", open ? "rotate-180" : "")} />
      </button>
      {open ? (
        <div id={bodyId} className="border-t border-border px-4 py-4">
          {children}
        </div>
      ) : null}
    </div>
  );
}
