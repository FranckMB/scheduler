import { CalendarCheck2, LogOut, Menu as MenuIcon, Moon, Settings, Sun, User, ShieldCheck } from "lucide-react";
import { NavLink, Outlet } from "react-router-dom";

import { useLogout, useMe } from "@/features/auth/queries";
import { Menu, MenuItem } from "@/shared/components/ui/menu";
import { useApplyClubTheme } from "@/shared/hooks/useApplyClubTheme";
import { cn } from "@/shared/lib/utils";
import { useThemeStore } from "@/shared/stores/themeStore";

import { ReadonlySeasonBanner } from "./ReadonlySeasonBanner";
import { DevClock } from "./DevClock";
import { SeasonSelector } from "./SeasonSelector";
import { SeasonTransitionBanner } from "./SeasonTransitionBanner";

function NavItem({ to, children }: { to: string; children: string }) {
  return (
    <NavLink
      to={to}
      end
      className={({ isActive }) =>
        cn("rounded-md px-3 py-1.5 text-sm transition-colors", isActive ? "bg-accent text-accent-foreground" : "text-muted-foreground hover:text-foreground")
      }
    >
      {children}
    </NavLink>
  );
}

export function AppLayout() {
  const { data } = useMe();
  const logout = useLogout();
  useApplyClubTheme();
  const mode = useThemeStore((state) => state.mode);
  const toggleMode = useThemeStore((state) => state.toggleMode);

  return (
    <div className="min-h-screen bg-background text-foreground">
      <header className="border-b border-border">
        <div className="mx-auto flex h-14 max-w-5xl items-center justify-between gap-4 px-4">
          {/* The club title IS the home link — everything else (planning,
              assistant) is reached from the cockpit, not the top bar. */}
          <div className="flex min-w-0 items-center gap-2">
            <NavLink to="/" aria-label="Accueil" title="Retour à l'accueil (tableau de bord)" className="flex items-center gap-2 rounded-md transition-opacity hover:opacity-80">
              {data?.club?.logoUrl ? (
                <img src={data.club.logoUrl} alt="" className="size-6 rounded-full object-cover" />
              ) : (
                <CalendarCheck2 className="size-5 text-accent" />
              )}
              <span className="truncate text-sm font-semibold">{data?.club?.name ?? "ClubScheduler"}</span>
            </NavLink>
            {import.meta.env.DEV ? <DevClock /> : null}
          </div>
          <nav className="flex items-center gap-1">
            <SeasonSelector />
            {/* Matches stay locked until the season's main plan is validated. */}
            {null !== data?.socleValidatedAt ? (
              <NavItem to="/matchs">Matchs</NavItem>
            ) : (
              <span
                aria-disabled="true"
                className="cursor-not-allowed rounded-md px-3 py-1.5 text-sm text-muted-foreground/40"
                title="Validez le planning principal pour débloquer les matchs"
              >
                Matchs
              </span>
            )}
            <Menu label="Menu du compte" trigger={<MenuIcon />}>
              <MenuItem to="/club" icon={<Settings />}>
                Club
              </MenuItem>
              <MenuItem to="/profile" icon={<User />}>
                Profil
              </MenuItem>
              <MenuItem icon={mode === "dark" ? <Sun /> : <Moon />} onSelect={toggleMode}>
                {mode === "dark" ? "Thème clair" : "Thème sombre"}
              </MenuItem>
              <MenuItem to="/confidentialite" icon={<ShieldCheck />}>
                Confidentialité
              </MenuItem>
              <MenuItem icon={<LogOut />} className="text-destructive [&_svg]:text-destructive" onSelect={logout}>
                Se déconnecter
              </MenuItem>
            </Menu>
          </nav>
        </div>
      </header>
      <main className="mx-auto max-w-5xl px-4 py-8">
        <ReadonlySeasonBanner />
        <SeasonTransitionBanner />
        <Outlet />
      </main>
    </div>
  );
}
