import { Download, FileImage, FileSpreadsheet, FileText, Loader2 } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Select } from "@/shared/components/ui/select";
import { useCredits } from "@/shared/credits/useCredits";

import type { Venue } from "./api";
import { type ExportFormat, useScheduleExport } from "./queries";

/**
 * Export the currently viewed schedule to PDF / PNG / Excel, scoped to every gym
 * or a single one (venue-only, per product). Each export fits one landscape page.
 */
export function ExportMenu({
  scheduleId,
  venues,
  exportName = null,
  screenFilterCount = 0,
}: {
  scheduleId: string;
  venues: Venue[];
  exportName?: string | null;
  /**
   * P4-62 — combien de ressources le filtre de l'ÉCRAN masque actuellement. Le
   * filtre est une loupe de lecture, jamais le périmètre de l'export (le PDF est
   * rendu serveur, qui n'en sait rien) : décision fondateur « annoncer, ne pas
   * coupler ». On le DIT donc ici, plutôt que de laisser croire que ce qu'on voit
   * est ce qu'on emporte — la règle maison est de ne jamais masquer ce qu'un
   * export contient.
   */
  screenFilterCount?: number;
}) {
  const [open, setOpen] = useState(false);
  const [scope, setScope] = useState<string>(""); // "" = all venues
  const rootRef = useRef<HTMLDivElement>(null);
  const { run, busy } = useScheduleExport(scheduleId, exportName);
  // §4bis pt 2 — les TROIS exports (PDF/PNG/Excel) consomment 1 crédit : chaque
  // entrée affiche le solde et se désactive à 0 (Découverte bridée). null = offre
  // non bridée (payant/bêta/démo) : ni suffixe, ni blocage.
  const credits = useCredits();
  const creditSuffix = null !== credits ? ` (${credits.remaining})` : "";
  const creditsBlocked = null !== credits && !credits.canExportPdf;

  // Close on outside click / Escape.
  useEffect(() => {
    if (!open) {
      return;
    }
    const onDown = (e: MouseEvent): void => {
      if (null !== rootRef.current && !rootRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    const onKey = (e: KeyboardEvent): void => {
      if ("Escape" === e.key) {
        setOpen(false);
      }
    };
    document.addEventListener("mousedown", onDown);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDown);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  const venueId = "" === scope ? null : scope;
  const scopeLabel = null === venueId ? "tous les gymnases" : (venues.find((v) => v.id === venueId)?.name ?? "un gymnase");
  const formats: { key: ExportFormat; label: string; icon: typeof FileText }[] = [
    { key: "pdf", label: "PDF", icon: FileText },
    { key: "png", label: "Image (PNG)", icon: FileImage },
    { key: "xlsx", label: "Excel", icon: FileSpreadsheet },
  ];

  return (
    <div ref={rootRef} className="relative">
      <Button variant="outline" size="sm" onClick={() => setOpen((o) => !o)} aria-haspopup="menu" aria-expanded={open}>
        <Download className="size-4" />
        Exporter
      </Button>
      {open ? (
        // z-50: above the WeekGrid frozen headers (z-40 corner / z-30 rows), which
        // otherwise render over the dropdown ("coupent" le menu — revue #204).
        <div role="menu" className="absolute right-0 z-50 mt-1 w-64 rounded-lg border border-border bg-card p-3 shadow-lg">
          <label className="mb-1 block text-xs font-medium text-muted-foreground" htmlFor="export-scope">
            Périmètre
          </label>
          <Select id="export-scope" aria-label="Périmètre de l'export" className="mb-3 h-9 w-full" value={scope} onChange={(e) => setScope(e.target.value)}>
            <option value="">Tous les gymnases</option>
            {venues.map((v) => (
              <option key={v.id} value={v.id}>
                {v.name}
              </option>
            ))}
          </Select>
          <div className="flex flex-col gap-1">
            {formats.map(({ key, label, icon: Icon }) => (
              <button
                key={key}
                type="button"
                role="menuitem"
                disabled={null !== busy || creditsBlocked}
                onClick={() => void run(key, venueId)}
                className="flex items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted disabled:opacity-50"
              >
                {busy === key ? <Loader2 className="size-4 animate-spin" /> : <Icon className="size-4" />}
                {label}
                {creditSuffix}
              </button>
            ))}
          </div>
          {screenFilterCount > 0 ? (
            <p className="mt-2 rounded-md bg-muted p-2 text-xs leading-tight">
              L'écran est filtré ({screenFilterCount} {1 === screenFilterCount ? "ressource" : "ressources"}), mais
              l'export porte sur <strong>{scopeLabel}</strong> — le filtre d'affichage ne le restreint pas.
            </p>
          ) : null}
          <p className="mt-2 text-xs leading-tight text-muted-foreground">Une page paysage, ajustée pour rester lisible.</p>
        </div>
      ) : null}
    </div>
  );
}
