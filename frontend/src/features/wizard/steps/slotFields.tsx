import { Select } from "@/shared/components/ui/select";

const CAP_HINT = "Nombre d'équipes pouvant s'entraîner en même temps sur ce créneau (2 = terrain coupé en deux).";

/**
 * Sélecteur de capacité — seul un gymnase divisible (canSplit) accueille 2 ou 3
 * équipes (terrains en travers — retour fondateur 2026-08-05 : ADN se divise en 3) ;
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
      <option value={2}>2 équipes (terrain divisé en 2)</option>
      <option value={3}>3 équipes (terrain divisé en 3)</option>
    </Select>
  );
}

/**
 * Guidance affichée dès qu'une capacité ≥ 2 est CHOISIE (valeur du select, pas la valeur
 * enregistrée) : c'est au moment où le gestionnaire crée un créneau partagé qu'il faut lui
 * dire où se choisit QUI le partage — sans réservation, le système associe les équipes
 * lui-même (la capacité dit combien, jamais avec qui — décision P3-8). Module partagé pour
 * la même raison que `CapacitySelect` : les éditeurs de saison ET de période l'affichent.
 * ⚠ « le système », jamais « le solveur » : vocabulaire gestionnaire (docs/glossary.md).
 */
export function SharedSlotHint({ capacity }: { capacity: number }) {
  if (capacity < 2) {
    return null;
  }
  return (
    <p className="mt-3 rounded-md border border-border bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
      Créneau partagé : choisissez les {capacity} équipes qui l'occuperont en les réservant (étape Contraintes, onglet
      Réserver). Sans réservation, le système associera les équipes lui-même.
    </p>
  );
}
