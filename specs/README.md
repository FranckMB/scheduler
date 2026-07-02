# Living Specs System

Last verified @ 2026-07-02

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

- `specs/initiales/ClubScheduler_v3.md`
- `specs/courantes/`
- `specs/evolution/`

## Notes

This README documents the manual maintenance obligations for the living specs system.
It does not promise automated drift checks or CI enforcement.
