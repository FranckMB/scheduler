import { type ReactNode, useEffect, useId, useRef, useState } from "react";

import { cn } from "@/shared/lib/utils";

interface MenuProps {
  /** Accessible label for the trigger button (e.g. "Menu du compte"). */
  label: string;
  /** Trigger content (usually an icon). */
  trigger: ReactNode;
  children: ReactNode;
  className?: string;
}

/**
 * Minimal accessible dropdown menu. Trigger toggles a panel that closes on
 * Escape or an outside click. No external dependency (the project ships only
 * @radix-ui/react-slot + react-label, no dropdown primitive).
 */
export function Menu({ label, trigger, children, className }: MenuProps) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const menuId = useId();

  useEffect(() => {
    if (!open) {
      return;
    }
    const onPointer = (e: MouseEvent) => {
      if (null !== rootRef.current && !rootRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    const onKey = (e: KeyboardEvent) => {
      if ("Escape" === e.key) {
        setOpen(false);
      }
    };
    document.addEventListener("mousedown", onPointer);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onPointer);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  return (
    <div ref={rootRef} className={cn("relative", className)}>
      <button
        type="button"
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? menuId : undefined}
        onClick={() => setOpen((v) => !v)}
        className="inline-flex size-10 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background [&_svg]:size-5"
      >
        {trigger}
      </button>
      {open ? (
        <div
          id={menuId}
          role="menu"
          className="absolute right-0 z-20 mt-1 min-w-44 rounded-md border border-border bg-background p-1 shadow-lg"
          onClick={() => setOpen(false)}
        >
          {children}
        </div>
      ) : null}
    </div>
  );
}

interface MenuItemProps {
  onSelect?: () => void;
  icon?: ReactNode;
  children: ReactNode;
  className?: string;
}

/** A clickable row inside a Menu. Renders as role="menuitem". */
export function MenuItem({ onSelect, icon, children, className }: MenuItemProps) {
  return (
    <button
      type="button"
      role="menuitem"
      onClick={onSelect}
      className={cn(
        "flex w-full items-center gap-2 rounded-sm px-2.5 py-1.5 text-left text-sm text-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:bg-muted [&_svg]:size-4 [&_svg]:shrink-0 [&_svg]:text-muted-foreground",
        className,
      )}
    >
      {icon}
      {children}
    </button>
  );
}
