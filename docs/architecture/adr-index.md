# Architecture Decision Records — Index

This repo has **no formal ADRs yet**. This index is the entry point; add one numbered file per structural decision as they are made (or back-filled), and link it from the table below.

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
| [ADR-0001](adr-0001-single-pass-solve.md) | Single-pass solve, no silent fallback | accepted |
| [ADR-0002](adr-0002-pattern-plan.md) | Pattern « Plan » : un plan nommé, des versions, un pointeur | proposed |

## Candidate decisions to formalize
These are existing, load-bearing decisions found during onboarding that are currently implicit. Promote to ADRs when touched (do not invent rationale retroactively without confirming intent):

1. **Multi-tenant isolation = Doctrine `TenantFilter` + `ClubUser` membership check + PostgreSQL RLS (`SET LOCAL app.club_id`).** Security-critical, guarded by `TenantIsolationTest`. Refs: `backend/docs/TENANT.md`, `backend/docs/RLS.md`.
2. **Backend↔engine contract is hand-synced (no codegen)**, versioned via `engine/CONTRACT_VERSION`, guarded by `ContractSchemaTest`. Why no codegen?
3. **Async generation via Symfony Messenger + Redis + per-club lock**, progress over Mercure (`club:{id}:schedule:{id}`). Why this topology over synchronous generation.
4. **Solver timeout default 650 s, payload-driven** (`solver_timeout_seconds`). Rationale + the relationship to `pytest-timeout` test limits.

*Formalized / resolved:* the two-pass fallback decision → [ADR-0001](adr-0001-single-pass-solve.md); the Rector 8.3-vs-8.4 mismatch was fixed in code (Rector now targets 8.4), not via ADR — see roadmap §Dette (B1, résolu).

See `specs/evolution/roadmap.md` §Dette for the evidence behind item 4.
