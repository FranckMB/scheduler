import { Select } from "@/shared/components/ui/select";

const CAP_HINT = "Nombre d'équipes pouvant s'entraîner en même temps sur ce créneau (2 = terrain coupé en deux).";

/**
 * Sélecteur de capacité — seul un gymnase divisible (canSplit) accueille 2 équipes ;
 * sinon la capacité vaut toujours 1 et le contrôle disparaît.
 *
 * Extrait dans ce module partagé pour que l'éditeur de période le réutilise sans importer
 * `VenuesStep` (qui importe déjà `PeriodVenues` — l'import inverse serait circulaire). Son
 * absence dans l'éditeur de période dégradait en silence un créneau divisible à 1 terrain
 * (revue #8 PR-B round 2).
 */
export function CapacitySelect({ value, onChange, canSplit, className }: { value: number; onChange: (n: number) => void; canSplit: boolean; className?: string }) {
  if (!canSplit) {
    return null;
  }
  return (
    <Select aria-label="Capacité" title={CAP_HINT} className={className} value={value} onChange={(e) => onChange(Number(e.target.value))}>
      <option value={1}>1 équipe (terrain entier)</option>
      <option value={2}>2 équipes (terrain divisé)</option>
    </Select>
  );
}
