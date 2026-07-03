import { CalendarCheck2, LogOut, Menu as MenuIcon, Moon, Settings, Sun, User } from "lucide-react";
import { NavLink, Outlet, useNavigate } from "react-router-dom";

import { useLogout, useMe } from "@/features/auth/queries";
import { Menu, MenuItem } from "@/shared/components/ui/menu";
import { useApplyClubTheme } from "@/shared/hooks/useApplyClubTheme";
import { cn } from "@/shared/lib/utils";
import { useThemeStore } from "@/shared/stores/themeStore";

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
  const navigate = useNavigate();
  useApplyClubTheme();
  const mode = useThemeStore((state) => state.mode);
  const toggleMode = useThemeStore((state) => state.toggleMode);

  return (
    <div className="min-h-screen bg-background text-foreground">
      <header className="border-b border-border">
        <div className="mx-auto flex h-14 max-w-5xl items-center justify-between gap-4 px-4">
          <div className="flex items-center gap-2">
            {data?.club?.logoUrl ? (
              <img src={data.club.logoUrl} alt="" className="size-6 rounded-full object-cover" />
            ) : (
              <CalendarCheck2 className="size-5 text-accent" />
            )}
            <span className="text-sm font-semibold">{data?.club?.name ?? "ClubScheduler"}</span>
          </div>
          <nav className="flex items-center gap-1">
            <NavItem to="/">Accueil</NavItem>
            <NavItem to="/wizard">Assistant</NavItem>
            <Menu label="Menu du compte" trigger={<MenuIcon />}>
              <MenuItem icon={<Settings />} onSelect={() => navigate("/club")}>
                Club
              </MenuItem>
              <MenuItem icon={<User />} onSelect={() => navigate("/profile")}>
                Profil
              </MenuItem>
              <MenuItem icon={mode === "dark" ? <Sun /> : <Moon />} onSelect={toggleMode}>
                {mode === "dark" ? "Thème clair" : "Thème sombre"}
              </MenuItem>
              <MenuItem icon={<LogOut />} className="text-destructive [&_svg]:text-destructive" onSelect={logout}>
                Se déconnecter
              </MenuItem>
            </Menu>
          </nav>
        </div>
      </header>
      <main className="mx-auto max-w-5xl px-4 py-8">
        <Outlet />
      </main>
    </div>
  );
}
