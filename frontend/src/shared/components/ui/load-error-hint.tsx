import { Button } from "@/shared/components/ui/button";
import { cn } from "@/shared/lib/utils";

/**
 * Inline "ça n'a pas chargé" avec une ISSUE — le pendant de `EmptyHint` pour un
 * échec de lecture.
 *
 * Motif corrigé (P4-1 / P4-20) : un GET en échec était rendu comme un
 * chargement éternel ou comme une liste vide. Les deux mentent — « ça arrive »
 * et « il n'y a rien » ne sont pas « ça a échoué » — et laissent le
 * gestionnaire sans geste possible. Un écran qui échoue doit le DIRE et offrir
 * de réessayer.
 */
export function LoadErrorHint({
  children = "Le chargement a échoué.",
  onRetry,
  className,
}: {
  children?: React.ReactNode;
  onRetry?: () => void;
  className?: string;
}) {
  return (
    <div className={cn("flex flex-wrap items-center gap-2 text-sm text-destructive", className)} role="alert">
      <span>{children}</span>
      {onRetry ? (
        <Button type="button" variant="ghost" size="sm" onClick={onRetry}>
          Réessayer
        </Button>
      ) : null}
    </div>
  );
}
