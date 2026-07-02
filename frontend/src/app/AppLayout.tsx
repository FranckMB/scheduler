import { CalendarCheck2, LogOut, Moon, Sun } from "lucide-react";
import { NavLink, Outlet } from "react-router-dom";

import { useLogout, useMe } from "@/features/auth/queries";
import { Button } from "@/shared/components/ui/button";
import { useApplyClubTheme } from "@/shared/hooks/useApplyClubTheme";
import { cn } from "@/shared/lib/utils";
import { useThemeStore } from "@/shared/stores/themeStore";

function NavItem({ to, children }: { to: string; children: string }) {
  return (
    <NavLink
      to={to}
      end
      className={({ isActive }) =>
        cn("rounded-md px-3 py-1.5 text-sm transition-colors", isActive ? "bg-muted text-foreground" : "text-muted-foreground hover:text-foreground")
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
  const isAdmin = data?.role === "admin";

  return (
    <div className="min-h-screen bg-background text-foreground">
      <header className="border-b border-border">
        <div className="mx-auto flex h-14 max-w-5xl items-center justify-between gap-4 px-4">
          <div className="flex items-center gap-2">
            <CalendarCheck2 className="size-5 text-accent" />
            <span className="text-sm font-semibold">{data?.club?.name ?? "ClubScheduler"}</span>
          </div>
          <nav className="flex items-center gap-1">
            <NavItem to="/">Accueil</NavItem>
            <NavItem to="/wizard">Assistant</NavItem>
            <NavItem to="/club">Club</NavItem>
            {isAdmin ? <NavItem to="/pending-members">Demandes</NavItem> : null}
            <NavItem to="/profile">Profil</NavItem>
            <Button variant="ghost" size="icon" aria-label="Basculer le thème" onClick={toggleMode}>
              {mode === "dark" ? <Sun /> : <Moon />}
            </Button>
            <Button variant="ghost" size="icon" aria-label="Se déconnecter" onClick={logout}>
              <LogOut />
            </Button>
          </nav>
        </div>
      </header>
      <main className="mx-auto max-w-5xl px-4 py-8">
        <Outlet />
      </main>
    </div>
  );
}
