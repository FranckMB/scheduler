# Features — Agent Context

> 4 feature domains: auth, wizard, schedule, priorities. Feature-based architecture.

## Structure

```
features/
├── auth/           # Login, JWT auth store (Zustand)
│   ├── pages/LoginPage.tsx
│   └── authStore.ts
├── wizard/         # 8-step club setup wizard
│   ├── wizardStore.ts      # Zustand + persist (localStorage)
│   ├── WizardPage.tsx      # Step orchestrator
│   └── components/         # VenueStep, TeamStep, CoachStep, etc.
├── schedule/       # FullCalendar + Mercure SSE
│   ├── pages/ScheduleViewPage.tsx
│   ├── pages/DiagnosticsPage.tsx
│   └── components/
└── priorities/     # Drag & drop priority tiers
    ├── TierListPage.tsx
    └── TierColumn.tsx
```

## Key Conventions

- **Each feature** owns its pages, components, API hooks, and state.
- **Wizard store** uses Zustand `persist` middleware (localStorage key: `wizard-storage`).
- **Auto-save** : `wizardStore.autoSave()` posts sequentially to API. No batching.
- **Validation** : per-step validation in `validateStep()`.
- **Dark mode** : all components use `text-fg-primary`, `bg-base`, `glass` utilities.

## Critical Gotchas

1. **Wizard auto-save** — Sends `source: 'manual'` for venues. Fails if no `X-Club-Id` header.
2. **Auth store** — `initAuth()` recovers session on F5. Must set `club` from `/api/me` response.
3. **Schedule SSE** — `EventSource` on `/.well-known/mercure?topic=...`. Invalidates React Query cache.
4. **Error display** — `saveError` in `WizardPage` shows red alert box. Must have `bg-error-900/40` class.

## Anti-Patterns

- **Never** hardcode `clubId` — always get it from `authStore` or `/api/me`
- **Never** call `apiClient` outside `shared/api/client.ts` — use the `ky` instance with interceptors
- **Never** forget `X-Club-Id` header — `apiClient` adds it automatically from authStore
- **Never** use `fetch` directly — use `apiClient` for consistent auth/error handling

## Quick Reference

| Task | Location |
|------|----------|
| Fix login 401 | `LoginPage.tsx` → set token before `/me` call |
| Fix wizard save | `wizardStore.ts` → check `autoSave()` error handling |
| Fix dark mode | `index.css` → verify `bg-deep`, `text-fg-primary` tokens |
| Add new step | `WizardPage.tsx` → add to `STEP_COMPONENTS` array |
