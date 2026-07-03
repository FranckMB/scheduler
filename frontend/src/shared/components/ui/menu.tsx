import { createContext, type ReactNode, useContext, useEffect, useId, useRef, useState } from "react";
import { NavLink } from "react-router-dom";

import { cn } from "@/shared/lib/utils";

const MenuCloseContext = createContext<() => void>(() => {});

interface MenuProps {
  /** Accessible label for the trigger button (e.g. "Menu du compte"). */
  label: string;
  /** Trigger content (usually an icon). */
  trigger: ReactNode;
  children: ReactNode;
  className?: string;
}

const ITEM_SELECTOR = '[role="menuitem"]';

/**
 * Accessible dropdown menu (APG menu-button pattern, kept minimal — the
 * project ships no dropdown primitive). Opens focusing the first item;
 * Arrow Up/Down roam the items; Escape or Tab close and restore focus to the
 * trigger; an outside click closes. No external dependency.
 */
export function Menu({ label, trigger, children, className }: MenuProps) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const menuId = useId();

  const close = (restoreFocus = false) => {
    setOpen(false);
    if (restoreFocus) {
      triggerRef.current?.focus();
    }
  };

  // Move focus into the panel when it opens.
  useEffect(() => {
    if (open) {
      panelRef.current?.querySelector<HTMLElement>(ITEM_SELECTOR)?.focus();
    }
  }, [open]);

  // Close on outside click while open.
  useEffect(() => {
    if (!open) {
      return;
    }
    const onPointer = (e: MouseEvent) => {
      if (null !== rootRef.current && !rootRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener("mousedown", onPointer);
    return () => document.removeEventListener("mousedown", onPointer);
  }, [open]);

  const items = (): HTMLElement[] => Array.from(panelRef.current?.querySelectorAll<HTMLElement>(ITEM_SELECTOR) ?? []);

  const onPanelKeyDown = (e: React.KeyboardEvent) => {
    if ("Escape" === e.key) {
      e.preventDefault();
      close(true);
      return;
    }
    if ("Tab" === e.key) {
      // Leaving the menu with the keyboard closes it (no focus trap).
      close(false);
      return;
    }
    if ("ArrowDown" === e.key || "ArrowUp" === e.key) {
      e.preventDefault();
      const list = items();
      if (0 === list.length) {
        return;
      }
      const idx = list.indexOf(document.activeElement as HTMLElement);
      const next = "ArrowDown" === e.key ? (idx + 1) % list.length : (idx - 1 + list.length) % list.length;
      list[next]?.focus();
    }
  };

  return (
    <div ref={rootRef} className={cn("relative", className)}>
      <button
        ref={triggerRef}
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
          ref={panelRef}
          id={menuId}
          role="menu"
          aria-label={label}
          onKeyDown={onPanelKeyDown}
          className="absolute right-0 z-50 mt-1 min-w-44 rounded-md border border-border bg-background p-1 shadow-lg"
        >
          <MenuCloseContext.Provider value={() => close(false)}>{children}</MenuCloseContext.Provider>
        </div>
      ) : null}
    </div>
  );
}

interface MenuItemBaseProps {
  icon?: ReactNode;
  children: ReactNode;
  className?: string;
}

interface MenuActionProps extends MenuItemBaseProps {
  onSelect?: () => void;
  to?: undefined;
}

interface MenuLinkProps extends MenuItemBaseProps {
  /** Render as a NavLink (active route → aria-current) instead of a button. */
  to: string;
  onSelect?: undefined;
}

const ITEM_CLASS =
  "flex w-full items-center gap-2 rounded-sm px-2.5 py-1.5 text-left text-sm text-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:bg-muted aria-[current=page]:bg-muted aria-[current=page]:font-medium [&_svg]:size-4 [&_svg]:shrink-0 [&_svg]:text-muted-foreground";

/** A row inside a Menu — a button (onSelect) or, with `to`, a NavLink. Closing the menu is handled here. */
export function MenuItem({ icon, children, className, ...rest }: MenuActionProps | MenuLinkProps) {
  const close = useContext(MenuCloseContext);

  if (undefined !== rest.to) {
    return (
      <NavLink to={rest.to} end role="menuitem" onClick={() => close()} className={cn(ITEM_CLASS, className)}>
        {icon}
        {children}
      </NavLink>
    );
  }

  return (
    <button
      type="button"
      role="menuitem"
      onClick={() => {
        rest.onSelect?.();
        close();
      }}
      className={cn(ITEM_CLASS, className)}
    >
      {icon}
      {children}
    </button>
  );
}
