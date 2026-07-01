import { CalendarCheck2 } from "lucide-react";

import { useMe } from "@/features/auth/queries";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";

/**
 * Placeholder home for active members. The real planning dashboard (custom grid,
 * multi-view, generation loop) is built in tranche 2.
 */
export function DashboardHome() {
  const { data } = useMe();

  return (
    <div>
      <h1 className="mb-1 text-2xl font-semibold">Bonjour {data?.firstName} 👋</h1>
      <p className="mb-6 text-muted-foreground">Bienvenue dans l'espace de {data?.club?.name ?? "votre club"}.</p>
      <Card className="border-dashed">
        <CardHeader>
          <div className="flex items-center gap-2">
            <CalendarCheck2 className="size-5 text-accent" />
            <CardTitle>Planning</CardTitle>
          </div>
          <CardDescription>Le tableau de bord de génération et d'affinage du planning arrive dans la prochaine étape.</CardDescription>
        </CardHeader>
        <CardContent className="text-sm text-muted-foreground">Tranche 2 : grille semaine-type, vues par gymnase / coach / équipe, boucle générer → ajuster → valider.</CardContent>
      </Card>
    </div>
  );
}
