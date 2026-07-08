import { AlertTriangle } from "lucide-react";

/**
 * The "À corriger avant de générer" panel, shared by the Récap step and the
 * Génération step so the two gates never drift. Keys are index-based: two
 * distinct raw validator messages can humanize to the same French string, so
 * the text alone is not a unique key.
 */
export function BlockerList({ blockers, className }: { blockers: string[]; className?: string }) {
  if (0 === blockers.length) {
    return null;
  }
  return (
    <div className={`rounded-lg border border-destructive/50 bg-destructive/5 p-3 ${className ?? ""}`}>
      <div className="mb-1 flex items-center gap-2 text-sm font-medium text-destructive">
        <AlertTriangle className="size-4" />À corriger avant de générer
      </div>
      <ul className="list-inside list-disc text-sm text-destructive">
        {blockers.map((b, i) => (
          <li key={`${i}-${b}`}>{b}</li>
        ))}
      </ul>
    </div>
  );
}
