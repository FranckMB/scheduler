---
name: Documentation Update
description: After a feature, selectively updates the CLAUDE.md index / docs / specs and adds an ADR when a structural decision was made. No filler documentation. Invoke manually.
---

## Documentation Update

Run **only when the user asks**, after a feature whose **business behaviour, architecture, conventions, or public APIs actually changed**.

### Steps
1. **Decide if anything is worth writing.** Identify what genuinely changed among: business behaviour, service architecture, conventions, public APIs. If none changed, say so and write nothing.
2. **CLAUDE.md (short index).** Update only facts that are NOT obvious from filenames, keeping it under ~200 lines. Detail goes into `docs/`, not into the index.
3. **`docs/`.** Update the relevant file (`docs/project-map.md`, `docs/technique/`, `docs/testing/`, `docs/architecture/`).
4. **ADR.** If a structural/architectural decision was made, add an ADR and reference it in `docs/architecture/adr-index.md`.
5. **Living specs.** Update `specs/courantes/` if the real backend/engine state changed, per the triggers in `specs/README.md`.

### Rules
- **No filler.** Every added line must carry a fact a future agent would otherwise get wrong.
- **One canonical home.** Do not duplicate between `CLAUDE.md` (the short index) and `AGENTS.md` (a pointer to it) or `docs/project-map.md` (the detail). Index points to detail; detail is not copied back into the index.
