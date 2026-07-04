import { create } from "zustand";

export type ToastVariant = "error" | "success" | "info";

export interface Toast {
  id: number;
  message: string;
  variant: ToastVariant;
}

interface ToastState {
  toasts: Toast[];
  push: (message: string, variant?: ToastVariant) => number;
  dismiss: (id: number) => void;
}

let seq = 0;
const AUTO_DISMISS_MS: Record<ToastVariant, number> = { error: 7000, success: 4000, info: 5000 };
const timers = new Map<number, number>();

export const useToastStore = create<ToastState>((set, get) => ({
  toasts: [],
  push: (message, variant = "error") => {
    const id = ++seq;
    set((s) => ({ toasts: [...s.toasts, { id, message, variant }] }));
    if (typeof window !== "undefined") {
      timers.set(id, window.setTimeout(() => get().dismiss(id), AUTO_DISMISS_MS[variant]));
    }
    return id;
  },
  dismiss: (id) => {
    // Cancel the auto-dismiss timer so a manual close doesn't leave it running.
    const timer = timers.get(id);
    if (timer !== undefined) {
      clearTimeout(timer);
      timers.delete(id);
    }
    set((s) => ({ toasts: s.toasts.filter((t) => t.id !== id) }));
  },
}));

/** Imperative helper for non-component code (query/mutation cache handlers). */
export const toast = {
  error: (message: string) => useToastStore.getState().push(message, "error"),
  success: (message: string) => useToastStore.getState().push(message, "success"),
  info: (message: string) => useToastStore.getState().push(message, "info"),
};
