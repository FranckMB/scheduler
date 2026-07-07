import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { TeamSelect } from "@/shared/components/ui/team-select";

import type { ImportFbiResult, PriorityTier, Team } from "./api";
import { useImportFbiFixtures } from "./queries";

interface ImportFbiDialogProps {
  teams: Team[];
  tiers: PriorityTier[];
  onClose: () => void;
}

/**
 * Upload one FBI export (.xlsx) for ONE team — the team is chosen here, never
 * guessed from the file. Stays open after the import to show the per-row report
 * (created / skipped / errors), which is the point of the feedback.
 */
export function ImportFbiDialog({ teams, tiers, onClose }: ImportFbiDialogProps) {
  const importFbi = useImportFbiFixtures();
  const [teamId, setTeamId] = useState(teams[0]?.id ?? "");
  const [file, setFile] = useState<File | null>(null);
  const [report, setReport] = useState<ImportFbiResult | null>(null);

  const canImport = "" !== teamId && null !== file && !importFbi.isPending;

  const submit = (): void => {
    if (null === file || "" === teamId) {
      return;
    }
    importFbi.mutate({ teamId, file }, { onSuccess: (result) => setReport(result) });
  };

  return (
    <Modal label="Importer FBI" title="Importer un export FBI" onClose={onClose}>
      <div className="flex flex-col gap-3">
        <p className="text-xs text-muted-foreground">
          Un fichier .xlsx par équipe (export FBI des rencontres). Les matchs déjà importés sont ignorés.
        </p>

        <label className="flex flex-col gap-1 text-sm">
          <span className="text-muted-foreground">Équipe</span>
          <TeamSelect aria-label="Équipe" teams={teams} tiers={tiers} value={teamId} onChange={(e) => setTeamId(e.target.value)} />
        </label>

        <label className="flex flex-col gap-1 text-sm">
          <span className="text-muted-foreground">Fichier FBI (.xlsx)</span>
          <input
            aria-label="Fichier FBI"
            type="file"
            accept=".xlsx"
            className="text-sm"
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
          />
        </label>

        {null !== report ? (
          <div className="rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
            <p className="font-medium">
              {report.created} créé{report.created > 1 ? "s" : ""} · {report.skipped} ignoré{report.skipped > 1 ? "s" : ""}
            </p>
            {report.errors.length > 0 ? (
              <ul className="mt-1 max-h-40 list-inside list-disc overflow-y-auto text-xs text-destructive">
                {report.errors.map((error, i) => (
                  <li key={i}>{error}</li>
                ))}
              </ul>
            ) : null}
          </div>
        ) : null}

        <div className="mt-1 flex justify-end gap-2">
          <Button variant="outline" size="sm" onClick={onClose}>
            Fermer
          </Button>
          <Button size="sm" disabled={!canImport} onClick={submit}>
            Importer
          </Button>
        </div>
      </div>
    </Modal>
  );
}
