import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";

import type { Competition, HomeAway, Team } from "./api";
import { useCreateFixture } from "./queries";

interface FixtureFormDialogProps {
  teams: Team[];
  competitions: Competition[];
  onClose: () => void;
}

const fieldClass = "h-9 w-full rounded-md border border-input bg-background px-2 text-sm";

/** Manual entry of a fixture (until the FBI import, PR-4). Friendly = no competition. */
export function FixtureFormDialog({ teams, competitions, onClose }: FixtureFormDialogProps) {
  const createFixture = useCreateFixture();
  const [teamId, setTeamId] = useState(teams[0]?.id ?? "");
  const [matchDate, setMatchDate] = useState("");
  const [homeAway, setHomeAway] = useState<HomeAway>("HOME");
  const [opponentLabel, setOpponentLabel] = useState("");
  const [competitionId, setCompetitionId] = useState("");

  const valid = "" !== teamId && "" !== matchDate && "" !== opponentLabel.trim();
  const teamCompetitions = competitions.filter((c) => c.teamId === teamId);

  const submit = (): void => {
    if (!valid) {
      return;
    }
    createFixture.mutate(
      { teamId, matchDate, homeAway, opponentLabel: opponentLabel.trim(), competitionId: "" === competitionId ? null : competitionId },
      { onSuccess: onClose },
    );
  };

  return (
    <Modal label="Nouveau match" title="Nouveau match" onClose={onClose}>
      <div className="flex flex-col gap-3">
        <label className="flex flex-col gap-1 text-sm">
          <span className="text-muted-foreground">Équipe</span>
          <select aria-label="Équipe" className={fieldClass} value={teamId} onChange={(e) => setTeamId(e.target.value)}>
            {teams.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-1 text-sm">
          <span className="text-muted-foreground">Date</span>
          <input aria-label="Date" type="date" className={fieldClass} value={matchDate} onChange={(e) => setMatchDate(e.target.value)} />
        </label>

        <label className="flex flex-col gap-1 text-sm">
          <span className="text-muted-foreground">Domicile / Extérieur</span>
          <select aria-label="Domicile ou extérieur" className={fieldClass} value={homeAway} onChange={(e) => setHomeAway(e.target.value as HomeAway)}>
            <option value="HOME">Domicile</option>
            <option value="AWAY">Extérieur</option>
          </select>
        </label>

        <label className="flex flex-col gap-1 text-sm">
          <span className="text-muted-foreground">Adversaire</span>
          <input aria-label="Adversaire" type="text" className={fieldClass} value={opponentLabel} onChange={(e) => setOpponentLabel(e.target.value)} placeholder="Nom de l'équipe adverse" />
        </label>

        <label className="flex flex-col gap-1 text-sm">
          <span className="text-muted-foreground">Compétition (vide = amical)</span>
          <select aria-label="Compétition" className={fieldClass} value={competitionId} onChange={(e) => setCompetitionId(e.target.value)}>
            <option value="">Amical</option>
            {teamCompetitions.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </label>

        <div className="mt-1 flex justify-end gap-2">
          <Button variant="outline" size="sm" onClick={onClose}>
            Annuler
          </Button>
          <Button size="sm" disabled={!valid || createFixture.isPending} onClick={submit}>
            Créer
          </Button>
        </div>
      </div>
    </Modal>
  );
}
