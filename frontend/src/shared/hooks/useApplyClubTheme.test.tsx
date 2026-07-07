import { renderHook } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { accentForMode } from "@/shared/lib/color";
import { useThemeStore } from "@/shared/stores/themeStore";

import { useApplyClubTheme } from "./useApplyClubTheme";

type Club = { accentColor: string | null; accentColorDark: string | null; accentPalette: string[] | null };
let club: Club | null = null;

vi.mock("@/features/auth/queries", () => ({ useMe: () => ({ data: club ? { club } : undefined }) }));

const accentVar = () => document.documentElement.style.getPropertyValue("--accent");

afterEach(() => {
  document.documentElement.removeAttribute("style");
  club = null;
});

describe("useApplyClubTheme — per-mode club accent", () => {
  it("honours the explicit DARK accent in dark mode (used as-is, not derived)", () => {
    club = { accentColor: "#3b82f6", accentColorDark: "#f59e0b", accentPalette: null };
    useThemeStore.setState({ mode: "dark" });
    renderHook(() => useApplyClubTheme());
    expect(accentVar()).toBe("#f59e0b");
  });

  it("uses the LIGHT accent in light mode", () => {
    club = { accentColor: "#3b82f6", accentColorDark: "#f59e0b", accentPalette: null };
    useThemeStore.setState({ mode: "light" });
    renderHook(() => useApplyClubTheme());
    expect(accentVar()).toBe(accentForMode("#3b82f6", "light"));
  });

  it("derives the dark accent from the light one when no dark colour is set", () => {
    club = { accentColor: "#3b82f6", accentColorDark: null, accentPalette: null };
    useThemeStore.setState({ mode: "dark" });
    renderHook(() => useApplyClubTheme());
    expect(accentVar()).toBe(accentForMode("#3b82f6", "dark"));
  });

  it("clears the accent when the club has none", () => {
    club = { accentColor: null, accentColorDark: null, accentPalette: null };
    useThemeStore.setState({ mode: "dark" });
    renderHook(() => useApplyClubTheme());
    expect(accentVar()).toBe("");
  });
});
