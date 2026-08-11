---
paths:
  - "landing/**"
---

# Landing — conventions & pièges (chargé quand landing/ est touché)

> La page de vente publique. **Elle n'a pas d'`AGENTS.md`** : tout ce qui la concerne tient ici.

- **Zéro build.** HTML/CSS statique servi tel quel — pas de npm, pas de bundler, pas de
  transpilation. On édite `index.html` et `assets/` directement. N'introduis **aucune** chaîne de
  build : c'est ce qui rend cette page increvable et déployable seule.
- **Aucun lien avec `frontend/`** — pas d'import de composant, pas de CSS partagé, pas de brique
  commune. Les deux zones se ressemblent par **convention**, jamais par dépendance. Dupliquer une
  couleur ici est le comportement VOULU.
- **Marque, liens et coordonnées vivent dans `config.js` SEUL** — jamais en dur dans `index.html`.
  Le nom commercial n'est pas tranché (chaîne INPI, cf. `business/administratif-mise-en-prod.md`) :
  tout ce qui en dépend doit rester modifiable en un point.
- **Deux vhosts, une machine** : le domaine **nu** sert `landing/`, un **sous-domaine** sert l'app.
  Un lien « Se connecter » vers l'app est donc un lien absolu inter-domaines, pas une route.
- **Passe de design obligatoire** dès qu'on remanie l'apparence — c'est une page **publique** :
  agent `ui-ux-pro-max`, bornée à l'apparence, elle ne valide rien
  (`specs/courantes/frontend-strategy.md`, règle du 2026-08-11).
- ⚠ **Le contraste WCAG se vérifie dans un vrai navigateur**, jamais à l'œil ni en jsdom — les
  couleurs sont en `oklch`, la conversion vers sRGB passe par un canvas (P5-5 a corrigé des
  contrastes qui « paraissaient » bons).
- **Aucune donnée personnelle collectée sans mentions légales ni politique de confidentialité**
  (LCEN + RGPD) : un formulaire de contact sur cette page déclenche les deux obligations —
  `business/administratif-mise-en-prod.md` §9.
