# Living Specs System

Last verified @ 2026-07-05 (réorganisation evolution : roadmap = index unique)

## 3-Tier Structure

- `specs/initiales/` : besoin d'origine (v2/v3), **figé — jamais modifié**. L'évolution se lit dans le delta `initiales` → `courantes` (+ git). Pas de dossier `archive/`.
- `specs/courantes/` : **ce que l'appli fait aujourd'hui**. Doit refléter le code : si une spec ne colle plus → on la **met à jour** ; si la feature a disparu → on la **supprime**.
- `specs/evolution/` : **ce que l'appli fera plus tard** (backlog + gaps ouverts). Quand un item est **livré**, il **quitte** evolution (il gradue dans `courantes`). Les notes de process/décisions **résolues** n'y restent pas.

## Audiences

- initiales = origine (référence historique).
- courantes = développeurs / agents (vérité courante).
- evolution = planification (futur).

## Update Triggers

- `courantes` : mise à jour quand le comportement change (ou suppression si la feature disparaît).
- `evolution` : on **retire** un item quand il est livré (graduation vers courantes) ; on **ajoute** un item quand un gap/feature futur est identifié.
- `initiales` : jamais modifié.

## Files Overview

- `specs/initiales/` — `ClubScheduler_v3.md` (spec produit consolidée, figée) · `ClubScheduler_Specification_des_contraintes_v2.md` (modèle de contraintes d'origine) · prompt orchestrateur v3.
- `specs/courantes/` — inventaires par zone (`backend-inventory`, `engine-inventory`, `frontend-spec`, `frontend-components`, `frontend-strategy`, `frontend-wizard`) · specs de features livrées graduées depuis evolution (`planning-lifecycle-validated`, `identite-visuelle-club`, `vacances-scolaires-jours-feries`) · `openapi-snapshot.json` + son meta (régénéré à chaque changement d'API).
- `specs/evolution/` — `roadmap.md` (**index unique** : toute évolution/gap/idée y laisse une trace) · fichiers de détail référencés depuis la roadmap quand une ligne ne suffit pas (liste des fichiers actifs tenue dans le header de la roadmap). Règle : un fichier de détail devenu sans objet (sujet livré/tranché) est supprimé après absorption dans la roadmap (`features-futures.md`, `backend-gaps.md`, `contraintes-modele-cible.md` absorbés le 2026-07-05 — leurs IDs `FF#n`/`G#n` restent cités comme réf historiques).
- `specs/audit/` — éditions d'audit horodatées (`AUDIT-<date>-<model>.md`, skill `/audit`) ; registre de findings à ID stables, comparaison inter-éditions.

## Notes

This README documents the manual maintenance obligations for the living specs system.
It does not promise automated drift checks or CI enforcement.
