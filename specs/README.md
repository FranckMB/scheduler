# Living Specs System

Last verified @ 2026-06-30

## 3-Tier Structure

- `specs/initiales/` : archive du besoin originel, jamais modifié.
- `specs/courantes/` : miroir de l'état réel backend/engine + spec forward frontend, lue par Claude Code.
- `specs/evolution/` : backlog, gaps, handoff packets, décisions non résolues — utilisé par Prometheus et user.

## Audiences

- initiales = archive.
- courantes = développeurs / Claude Code.
- evolution = planification.

## Update Triggers

- `courantes` update quand backend change ou plan frontend démarre.
- `evolution` update quand le plan se termine ou qu'un gap est identifié.
- `initiales` jamais modifié.

## Files Overview

- `specs/initiales/ClubScheduler_v3.md`
- `specs/courantes/`
- `specs/evolution/`

## Notes

This README documents the manual maintenance obligations for the living specs system.
It does not promise automated drift checks or CI enforcement.
