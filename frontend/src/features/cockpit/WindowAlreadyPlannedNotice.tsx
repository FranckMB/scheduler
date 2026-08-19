import { CalendarClock } from "lucide-react";

import { Button } from "@/shared/components/ui/button";

/**
 * ADR-0002 inv. 4 (P2-38 PR3) — le refus « une seule planification par fenêtre » rendu comme une
 * PROPOSITION à l'endroit du geste, pas comme un toast fugace. Le `message` vient du SERVEUR : il
 * NOMME déjà la période en place, sa fenêtre et les issues (modifier / supprimer le planning
 * existant, ou découper la période en semaines) — le front l'affiche tel quel (règle d'or). On
 * n'ajoute qu'un raccourci : ouvrir le planning en conflit là où il vit. Pas de bouton de
 * suppression ici (geste destructif — il a sa maison, `DeletePlanningButton`) : le texte y renvoie.
 */
export function WindowAlreadyPlannedNotice({ message, onOpen }: { message: string; onOpen: () => void }) {
  return (
    <div className="space-y-2 rounded-md border border-warning/60 bg-warning/10 px-3 py-2 text-sm text-foreground">
      <p className="flex items-start gap-2">
        <CalendarClock aria-hidden className="mt-0.5 size-4 shrink-0 text-warning" />
        <span>{message}</span>
      </p>
      <div className="flex justify-end">
        <Button variant="outline" size="sm" onClick={onOpen}>
          Ouvrir le planning en place
        </Button>
      </div>
    </div>
  );
}
