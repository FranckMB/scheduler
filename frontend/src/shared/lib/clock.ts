/**
 * « Aujourd'hui », pour tout le front — une seule source, pilotable en dev.
 *
 * Pourquoi : la moitié des écrans du cockpit décide de ce qu'elle affiche à partir de la
 * date du jour (radar, calendrier, semaines offertes à l'ajustement). Tant que cette date
 * vient d'un `new Date()` disséminé, **une situation datée ne peut pas être rejouée** :
 * on obtient la bonne saison mais jamais le bon jour, donc ni démo ni recette d'un cas
 * « à trois semaines des vacances » (P4-16). Ce module est le point de passage unique.
 *
 * ⚠ L'override est **strictement DEV** : la lecture de l'URL est derrière
 * `import.meta.env.DEV`, donc le code de production ne contient aucun chemin capable de
 * décaler l'horloge. Un utilisateur ne peut pas se fabriquer un « aujourd'hui ».
 *
 * Usage en dev : `http://localhost:5173/cockpit?today=2026-12-20`.
 *
 * P4-16 reste ouverte pour le SERVEUR (les cartes radar qu'il calcule lisent l'heure
 * réelle) : décaler le front seul suffit à rejouer un écran, pas un aller-retour complet.
 */

/** Local Y-m-d (évite le décalage UTC de toISOString). */
export function toISODate(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");

  return `${y}-${m}-${d}`;
}

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

/**
 * La date existe-t-elle vraiment ? La FORME ne suffit pas (revue #344) : `2026-13-01` et
 * `2026-02-31` passent la regex, et comme ces chaînes trient APRÈS toute date réelle, plus
 * aucune vacance ni semaine ne franchissait les filtres — le radar affichait « Rien à
 * l'horizon. Tout roule. » sur un club qui avait trois périodes à ajuster. On construit
 * donc la date et on vérifie qu'elle se relit à l'identique (le constructeur reporte :
 * le 31 février devient le 3 mars).
 */
function isRealDate(iso: string): boolean {
  if (!ISO_DATE.test(iso)) {
    return false;
  }
  const [y, m, d] = iso.split("-").map(Number);

  return toISODate(new Date(y, m - 1, d)) === iso;
}

let override: string | null = null;

/**
 * Fixe le « aujourd'hui » du front, ou le relâche avec `null`.
 *
 * Une valeur invalide est IGNORÉE plutôt que propagée : un `?today=hier` qui traverserait
 * les comparaisons de chaînes ISO donnerait des filtres silencieusement faux (`"hier" > "2026-…"`
 * est vrai lexicographiquement) — un écran qui ment est pire qu'un paramètre sans effet.
 */
export function setTodayOverride(iso: string | null): void {
  override = null !== iso && isRealDate(iso) ? iso : null;
}

/** La date du jour, ISO Y-m-d — l'override de dev s'il est posé, l'horloge sinon. */
export function todayISO(): string {
  return override ?? toISODate(new Date());
}

// Amorçage : lu UNE fois au chargement du module. En prod, `import.meta.env.DEV` est faux
// et le bundler élimine tout ce bloc — l'override n'est alors atteignable que par un appel
// explicite à `setTodayOverride` (ce que seuls les tests font).
if (import.meta.env.DEV && "undefined" !== typeof window) {
  setTodayOverride(new URLSearchParams(window.location.search).get("today"));
}
