# Architecture Decision Records — Index

This index is the entry point for the repo's ADRs; add one numbered file per structural decision as it is made (or back-filled), and link it from the table below.

## Convention
- File: `docs/architecture/adr-NNNN-short-title.md` (zero-padded, incrementing).
- Status: `proposed` · `accepted` · `superseded by adr-XXXX` · `deprecated`.
- An ADR is warranted when a decision is **structural** (boundaries, data model, cross-zone contract, security model, infra topology) — not for routine changes.

## Template
```
# ADR-NNNN — <title>
- Status: <proposed|accepted|…>   Date: <YYYY-MM-DD>
- Context: <forces, constraints, what made this a decision>
- Decision: <what was chosen>
- Consequences: <trade-offs, follow-ups, what this rules out>
- Alternatives considered: <options + why rejected>
```

## Index
| ADR | Title | Status |
|-----|-------|--------|
| [ADR-0001](adr-0001-single-pass-solve.md) | Single-pass solve, no silent fallback | accepted — amended 2026-07-07 (two-PHASE lexicographic objective) and 2026-07-10 (A10 complexity cap) |
| [ADR-0002](adr-0002-pattern-plan.md) | Pattern « Plan » : un plan nommé, des versions, un pointeur | accepted — amended 2026-07-17 (les contraintes datées du FAIT restent sur `CalendarEntry`) and 2026-07-24 (**#8**: inv. 5 — une période POSSÈDE sa grille, copie et non union ; inv. 14 — reprendre le socle détruit tout plan de période **pas encore commencée**, validé ou non) |
| [ADR-0003](adr-0003-match-placement-solve.md) | Le solve de placement des matchs : second problème engine (`/place-matches`), rail synchrone, best-effort à poids dominant, ancres `placementSource` | accepted (2026-08-03, P1-4 PR D) |

*Non-ADR hosted here*: [`constraint-matrix.md`](constraint-matrix.md) — the UI ↔ engine constraint matrix (structuring axis §7.1 "constraint semantics"), including why a HARD lock overrides an entered HARD constraint and how it now says so.

## Candidate decisions to formalize
These are existing, load-bearing decisions found during onboarding that are currently implicit. Promote to ADRs when touched (do not invent rationale retroactively without confirming intent):

1. **Multi-tenant isolation = Doctrine `TenantFilter` + `ClubUser` membership check + PostgreSQL RLS** (GUC `app.club_id` set through `set_config`, session-scoped — **never `SET LOCAL`**, which is a no-op outside a transaction). Security-critical, guarded by `TenantIsolationTest` and `RlsIsolationTest`. Refs: `backend/docs/TENANT.md`, `backend/docs/RLS.md`, `../security/rls.md`.
2. **Backend↔engine contract is hand-synced (no codegen)**, versioned via `engine/CONTRACT_VERSION`, guarded by `ContractSchemaTest`. Why no codegen?
3. **Async generation via Symfony Messenger + Redis + per-club lock**, progress over Mercure (`club:{id}:schedule:{id}`). Why this topology over synchronous generation.
*Formalized / resolved:* the two-pass fallback decision → [ADR-0001](adr-0001-single-pass-solve.md); the **solver budget** (adaptive 60/180/600 s tiers, the payload's `solver_timeout_seconds` acting as a ceiling only, plus the A10 complexity cap) → ADR-0001, amendments 2026-07-07 and 2026-07-10; the Rector 8.3-vs-8.4 mismatch was fixed in code (Rector now targets 8.4), not via ADR — see roadmap §Dette (B1, résolu).
