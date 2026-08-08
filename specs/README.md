# Living Specs System

Last verified @ 2026-08-08 (règle « initiales figées » précisée — modifier ≠ archiver une pièce source, audit DOC-30 ; règle des deux fichiers re-vérifiée contre roadmap/état-des-lieux)

## 3-Tier Structure

- `specs/initiales/` : besoin d'origine (v2/v3), **figé — jamais modifié**. L'évolution se lit dans le delta `initiales` → `courantes` (+ git). Pas de dossier `archive/`. ⚠ **« Figé » interdit de MODIFIER, pas d'ARCHIVER** : une pièce source reçue du terrain peut être déposée ici (ex. `rechercherRencontre.xlsx`, export FBI joint au cadrage P1-4 le 2026-08-02) — elle entre telle quelle et ne bouge plus. Un dépôt n'est légitime que s'il s'agit d'une **pièce d'origine** ; toute production de l'équipe va dans `courantes/` ou `evolution/`.
- `specs/courantes/` : **ce que l'appli fait aujourd'hui**. Doit refléter le code : si une spec ne colle plus → on la **met à jour** ; si la feature a disparu → on la **supprime**. Point d'entrée : [`etat-des-lieux.md`](courantes/etat-des-lieux.md) — carte des capacités livrées, **décisions fermées**, traces datées.
- `specs/evolution/` : **ce que l'appli fera plus tard** (backlog + gaps ouverts). Quand un item est **livré**, il **quitte** evolution (il gradue dans `courantes`). Les notes de process/décisions **résolues** n'y restent pas.

**La règle des deux fichiers (refonte 2026-07-31).** `evolution/roadmap.md` ne tient que **l'ouvert** ; `courantes/etat-des-lieux.md` tient **le livré**. Un item livré **MOVE** : sa ligne est supprimée de la roadmap et une trace datée est ajoutée à l'état des lieux, avec le comportement documenté dans la spec courante qui le reçoit. Jamais les deux, jamais aucun. Corollaire : « est-ce que X est fait ? » ne se répond **pas** dans la roadmap.

## Audiences

- initiales = origine (référence historique).
- courantes = développeurs / agents (vérité courante).
- evolution = planification (futur).

## Update Triggers

- **Déclencheur unique : `documentation-update`, exécuté AVANT chaque PR** (CLAUDE.md §7 étape 6). La doc est vivante — une PR qui corrige ou ajoute quelque chose a de la doc à mettre à jour quelque part.
- `courantes` : mise à jour quand le comportement change (ou suppression si la feature disparaît) ; `etat-des-lieux.md` reçoit la trace datée et, si besoin, la décision fermée.
- `evolution` : on **retire** un item quand il est livré (graduation vers courantes) ; on **ajoute** un item quand un gap/bug/feature futur est identifié — avec sa preuve `fichier:ligne` vérifiée dans le code.
- `initiales` : jamais modifié.

## Files Overview

- `specs/initiales/` — `ClubScheduler_v3.md` (spec produit consolidée, figée) · `ClubScheduler_Specification_des_contraintes_v2.md` (modèle de contraintes d'origine) · prompt orchestrateur v3.
- `specs/courantes/` — `etat-des-lieux.md` (**point d'entrée** : ce que l'app sait faire, décisions fermées, traces datées) · inventaires par zone (`backend-inventory`, `engine-inventory`, `frontend-spec`, `frontend-components`, `frontend-strategy`, `frontend-wizard`) · specs de features livrées graduées depuis evolution (`planning-lifecycle-validated`, `types-de-planning` — les 3 types (socle / overlay / vacances) et l'axe collecte coach · `superadmin-auth` — console SA0-SA4 + monitoring · `identite-visuelle-club`, `vacances-scolaires-jours-feries`, `accueil-cockpit-temporel` — calendrier d'exceptions/overlays livré #122 · `module-matchs` — import FBI + placement + radar conflits) · `generation-pipeline` (conduite normalisée bout en bout front→backend→engine→import→affichage + invariants silencieux) · `openapi-snapshot.json` + son meta (régénéré à chaque changement d'API).
- `specs/evolution/` — `roadmap.md` (**index unique de l'OUVERT** : toute évolution/gap/idée non livrée y laisse une ligne) · fichiers de détail référencés depuis la roadmap quand une ligne ne suffit pas (liste des fichiers actifs tenue dans le header de la roadmap). Règle : un fichier de détail devenu sans objet (sujet livré/tranché) est supprimé après absorption dans la roadmap (`features-futures.md`, `backend-gaps.md`, `contraintes-modele-cible.md` absorbés le 2026-07-05 — leurs IDs `FF#n`/`G#n` restent cités comme réf historiques).
- `specs/audit/` — éditions d'audit horodatées (`AUDIT-<date>-<model>.md`, skill `/audit`) ; registre de findings à ID stables, comparaison inter-éditions.

## Notes

This README documents the manual maintenance obligations for the living specs system.
It does not promise automated drift checks or CI enforcement.
