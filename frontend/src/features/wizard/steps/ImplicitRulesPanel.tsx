/**
 * P4-55 — « qu'est-ce qui s'applique déjà, sans que je l'aie saisi ? »
 *
 * Le solveur pose une douzaine de règles structurelles que personne n'entre nulle part
 * (`engine/app/solver/constraints.py`, `add_level_1_hard_constraints`). Le gestionnaire ne
 * les voyait pas : il ne savait donc pas ce qu'il obtient gratuitement, ni pourquoi le
 * solveur refuse parfois un placement qui « aurait dû » passer.
 *
 * ⚠ **Lecture seule, et ce n'est pas un choix d'UI paresseux** : ces règles ne sont pas
 * éditables côté moteur. Un panneau qui laisserait croire l'inverse serait pire que pas de
 * panneau.
 *
 * ⚠ **Six règles, pas douze — tri assumé (décision fondateur 2026-08-11).** On montre celles
 * qu'un non-technicien peut reconnaître comme des faits de son gymnase. Sont tues :
 * `add_age_ascending_constraints` (les jeunes avant les grands sur un même gymnase+jour),
 * `add_salarie_distribution_constraints` (au moins un salarié présent chaque jour ouvré) et
 * `add_max_consecutive_sessions_constraints` (pas trois créneaux consécutifs pour un coach)
 * — des détails d'implémentation ou des règles de confort, dont l'énoncé coûterait plus de
 * confusion qu'il n'apporte.
 *
 * ⚠ **Le texte ci-dessous est un CONTRAT avec le moteur, pas de la prose.** Deux formulations
 * ont été corrigées en le rédigeant, contre ce que l'écran affirmait jusqu'ici :
 *  - un coach PEUT encadrer deux équipes à la fois **dans le même gymnase** (D-14, arbitrage
 *    fondateur 2026-08-09 — `constraints.py:317-325`). L'ancien intitulé « coach jamais en
 *    double » était faux, et il décourageait une pratique que le produit autorise ;
 *  - un gymnase accueille « au plus sa CAPACITÉ » d'équipes, pas « une seule »
 *    (`add_room_at_most_one`, la capacité se règle par créneau).
 *
 * ⚠ « Une séance par jour » n'a AUCUNE exception. Un drapeau `allowMultipleSessionsPerDay`
 * exemptait jadis une équipe côté moteur, mais il était ABSENT de `TeamInput` : aucune route,
 * aucun écran ne l'écrivait, il valait `false` partout et la branche d'exemption était morte.
 * Il a été retiré de bout en bout en P4-79 (moteur, payload backend, entité, schéma Pydantic),
 * jumeau du drapeau supprimé en P4-51. La règle s'applique donc sans condition — le texte ne
 * doit renvoyer vers aucun réglage.
 * Deux verrous gardent cet accord : le gel Vitest de ce fichier, et le test sémantique
 * `engine/tests/semantic/test_implicit_rules_are_still_applied.py`, qui vérifie que les six
 * fonctions correspondantes sont TOUJOURS appelées sans condition. Retirer une règle du
 * moteur sans toucher cet écran fait rougir la CI — sans quoi on continuerait d'annoncer une
 * règle morte.
 */

export interface ImplicitRule {
  id: string;
  title: string;
  detail: string;
}

/** L'ordre compte : du plus visible sur la grille au plus subtil. */
export const IMPLICIT_RULES: ImplicitRule[] = [
  {
    id: "venue-capacity",
    title: "Un gymnase ne dépasse jamais sa capacité",
    detail: "Sur un même créneau, un gymnase accueille au plus le nombre d'équipes que vous lui avez donné. Cette capacité se règle sur l'écran Gymnases, créneau par créneau.",
  },
  {
    id: "coach-two-venues",
    title: "Un coach n'est jamais dans deux gymnases à la fois",
    detail:
      "Deux équipes au même moment dans deux gymnases différents, c'est physiquement impossible : le solveur ne le proposera pas. En revanche, deux équipes au même moment dans le MÊME gymnase sont autorisées — le coach est présent une fois et surveille deux groupes.",
  },
  {
    id: "coach-player",
    title: "Une personne ne peut pas encadrer et jouer en même temps",
    detail: "Quand un coach est aussi joueur dans une autre équipe, ses deux rôles ne peuvent pas tomber sur le même créneau.",
  },
  {
    id: "team-overlap",
    title: "Une équipe n'a jamais deux séances en même temps",
    detail: "Une même équipe ne peut pas être placée sur deux créneaux qui se chevauchent.",
  },
  {
    id: "one-session-per-day",
    title: "Au plus une séance par jour et par équipe",
    detail: "Deux créneaux le même jour pour la même équipe ne sont jamais proposés.",
  },
  {
    id: "coach-rest-day",
    title: "Chaque coach garde un jour de repos",
    detail: "Par défaut, au moins un jour sans séance entre le lundi et le vendredi (les week-ends ne comptent pas dans ce calcul).",
  },
];

/**
 * L'encart replié par défaut : il informe, il n'occupe pas la place de ce que le
 * gestionnaire vient faire ici (saisir SES règles). `<details>`/`<summary>` natifs — le
 * clavier, le lecteur d'écran et l'état ouvert/fermé sont gérés par le navigateur, sans
 * `aria-expanded` à tenir à la main.
 */
export function ImplicitRulesPanel() {
  return (
    <details className="mb-4 rounded-md border border-border bg-muted/30">
      <summary className="cursor-pointer px-3 py-2 text-sm font-medium text-foreground">Ce que le système applique déjà, sans que vous le saisissiez</summary>
      <div className="border-t border-border px-3 py-2">
        <p className="mb-2 text-sm text-muted-foreground">Ces règles s'appliquent d'office, sans saisie. Elles s'ajoutent à celles que vous saisissez ci-dessous.</p>
        <ul className="flex flex-col gap-2">
          {IMPLICIT_RULES.map((rule) => (
            <li key={rule.id} className="text-sm">
              <span className="font-medium text-foreground">{rule.title}</span>
              <span className="text-muted-foreground"> — {rule.detail}</span>
            </li>
          ))}
        </ul>
      </div>
    </details>
  );
}
