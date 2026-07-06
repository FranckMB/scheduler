import { Lock } from "lucide-react";

import { useMe } from "@/features/auth/queries";
import { useSeasonStore } from "@/shared/stores/seasonStore";

/**
 * Banner shown while the manager is consulting an archived (read-only) season.
 * The server is the authority (every write → 409, SeasonAccessGuard); this
 * only makes the state visible so edits are not attempted blindly.
 */
export function ReadonlySeasonBanner() {
  const { data: me } = useMe();
  const selectedSeasonId = useSeasonStore((s) => s.selectedSeasonId);

  const selected = me?.seasons?.find((s) => s.id === selectedSeasonId);
  if (!selected?.isReadonly) {
    return null;
  }

  return (
    <div className="mb-4 flex items-center gap-2 rounded-md border border-border bg-muted px-3 py-2 text-sm text-muted-foreground" role="status">
      <Lock className="size-4 shrink-0" />
      <span>
        Saison <span className="font-medium text-foreground">{selected.name}</span> archivée — <span className="font-medium">lecture seule</span>. Reviens à la saison en cours pour modifier.
      </span>
    </div>
  );
}
