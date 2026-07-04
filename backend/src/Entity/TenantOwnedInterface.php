<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Explicit marker for entities that belong to a single club (own a `club_id`
 * column). Replaces the duck-typed `method_exists($e, 'getClubId')` checks that
 * decided tenant scoping in the State providers/processors (BCK-03).
 *
 * Layering (defence in depth):
 * - DB level — `TenantFilter` (Doctrine) and PostgreSQL RLS scope by the
 *   `club_id` *column*, so they stay fail-secure regardless of this marker.
 * - App level — the State providers/processors gate reads/writes by
 *   `instanceof TenantOwnedInterface` (type-safe, no reflection).
 * - `TenantOwnedInterfaceCompletenessTest` (phase1) keeps the marker set and
 *   the club_id-column set identical, so the app-layer guards are complete.
 *
 * `getClubId()` is nullable because a few entities (e.g. SportCategory) allow a
 * global row; a null club_id is simply never matched by the tenant predicate.
 */
interface TenantOwnedInterface
{
    public function getClubId(): ?string;

    public function setClubId(string $clubId): self;
}
