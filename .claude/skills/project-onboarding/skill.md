---
name: Project Onboarding
description: One-time, read-only deep analysis of engine + backend that produces the project context files (CLAUDE.md index + docs/) and a technical-debt audit. Frontend is excluded (slated for deletion + from-scratch React rebuild). Never modifies application code. Run only on explicit user request; re-playable later.
---

## Project Onboarding (read-only)

Run **only when the user explicitly asks**. Scope: **engine + backend only**. The current `frontend/` will be deleted and rebuilt from scratch in React — **exclude every frontend (or residual frontend) file from the debt audit** until told otherwise.

### Hard interdictions
- Never modify application code. Never refactor. Never delete a file.
- Never install a dependency or overwrite an existing configuration without explicit validation.
- This skill produces analysis + documentation only. No deletion or refactor is performed here, even when the audit identifies candidates.

### Steps
1. **Structure & commands** — Map the real structure of `engine/` and `backend/` and the install / build / test / lint commands. Cross-check `AGENTS.md`, the Makefiles, `docker-compose.yml`, and `.github/workflows/ci.yml`.
2. **Conventions & boundaries** — Identify existing conventions and the boundaries between modules (the backend↔engine contract: Pydantic schemas ⇄ API Platform resources, guarded by `ContractSchemaTest`).
3. **Symbol / dependency mapping** —
   - Use the already-installed **`code-review-graph`** MCP tools (`get_architecture_overview`, `list_communities`, `query_graph`, `semantic_search_nodes`, `get_impact_radius`).
   - **Serena** is planned but not yet installed. If it is already configured, read it and do not overwrite it. If not, propose its configuration but do NOT install it without explicit validation.
4. **Existing review tooling** — A custom review skill/agent already exists (`review-changes`, plus the `contrarian-review` agent). Read them; propose harmonisation with native `/code-review` rather than a duplicate; overwrite nothing without validation.
5. **Technical-debt audit over the whole engine+backend perimeter** (not a diff): dead code, duplicates, obsolete files, inconsistencies. Classify each as **delete / refactor / document / keep**, each with mandatory **proof**: not referenced in the graph, not imported, absent from build/tests, demonstrated duplicate, or a Git history of abandonment. *"The AI doesn't understand it" is never a proof.*

### Outputs (write these — do NOT touch application code)
- `CLAUDE.md` — the canonical **short** operational index (target < 200 lines): project goal, stack, repo structure, critical zones, boundaries, key conventions, key commands, workflow rules, documentation rules, and the Phase-2 scope checklist. Migrate the current `AGENTS.md` content into this index, push the detail into `docs/project-map.md`, and reduce `AGENTS.md` to a one-line pointer to `CLAUDE.md` (other tools still look for `AGENTS.md`).
- `docs/project-map.md` — the detailed repo map (the long-form detail behind the short CLAUDE.md index).
- `docs/testing/testing-strategy.md`
- `docs/architecture/adr-index.md`
- `docs/technical-debt.md`
- `docs/cleanup-candidates.md`

(No `REVIEW.md`: native reading of `REVIEW.md` by `/code-review` was not confirmed in Phase 0.)
