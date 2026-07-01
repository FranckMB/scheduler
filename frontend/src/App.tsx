import { CalendarCheck2, Moon, Sun } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { useThemeStore } from "@/shared/stores/themeStore";

/**
 * Foundation showcase — proves tokens (dark/light + accent), shadcn components,
 * and the theme store render. Replaced by the auth/dashboard routes next.
 */
export function App() {
  const mode = useThemeStore((state) => state.mode);
  const toggleMode = useThemeStore((state) => state.toggleMode);

  return (
    <main className="flex min-h-screen items-center justify-center bg-background p-6 text-foreground">
      <Card className="w-full max-w-md">
        <CardHeader>
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <CalendarCheck2 className="size-6 text-accent" />
              <CardTitle>ClubScheduler</CardTitle>
            </div>
            <Button variant="ghost" size="icon" aria-label="Basculer le thème" onClick={toggleMode}>
              {mode === "dark" ? <Sun /> : <Moon />}
            </Button>
          </div>
          <CardDescription>Fondations frontend — tokens dark/light + accent club, Tailwind 4, shadcn/ui.</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="demo-email">Email</Label>
            <Input id="demo-email" type="email" placeholder="gestionnaire@club.fr" />
          </div>
          <div className="flex gap-3">
            <Button className="flex-1">Action principale</Button>
            <Button variant="outline" className="flex-1">Secondaire</Button>
          </div>
        </CardContent>
      </Card>
    </main>
  );
}
