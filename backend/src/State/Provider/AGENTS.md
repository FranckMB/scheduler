# State Providers — Agent Context

> API Platform state providers for read operations. Override default Doctrine ORM behavior.

## Structure

| File | Role |
|------|------|
| `AbstractStateProvider.php` | Base with tenant filtering |
| `VenueStateProvider.php` | Venue collection/item |
| `TeamStateProvider.php` | Team collection/item |
| `...` | 10+ other entity providers |

## Pattern

All providers extend `AbstractStateProvider` which:
- **Filters by tenant** : adds `clubId` WHERE clause to all queries
- **Supports collection** : `getCollection()` for lists
- **Supports item** : `provide()` for single items

## Tenant Filter

```php
// AbstractStateProvider adds this automatically
$queryBuilder->andWhere('e.clubId = :clubId')
    ->setParameter('clubId', $request->attributes->get('_club_id'));
```

## Critical Gotchas

1. **Collection queries** — Always filtered by `clubId`. Cross-club data leaks are impossible.
2. **Item lookups** — The provider checks `clubId` matches before returning the item.
3. **Custom filters** — If you add `Filter` to an ApiResource, the provider must handle it.

## Anti-Patterns

- **Never** bypass `AbstractStateProvider` — tenant isolation is mandatory
- **Never** return unfiltered collections — always apply `clubId` filter
- **Never** query by ID alone — always include `clubId` in the WHERE clause

## Quick Reference

| Task | Location |
|------|----------|
| Add provider to new entity | Extend `AbstractStateProvider` + implement `getEntityClass()` |
| Fix missing data | Check `TenantFilterListener` sets `_club_id` on request |
| Custom query logic | Override `getCollection()` or `provide()` in child class |
