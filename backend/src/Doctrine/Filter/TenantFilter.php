<?php

declare(strict_types=1);

namespace App\Doctrine\Filter;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Doctrine SQL filter that enforces tenant isolation by appending
 * `club_id = ?` to every query on entities that own a `club_id` column.
 *
 * Kept column-based on purpose: it is **fail-secure**. Any entity with a
 * club_id column is scoped whether or not it declares the explicit
 * App\Entity\TenantOwnedInterface marker — so a forgotten marker can never open
 * a cross-club leak here (nor in RLS, which is likewise column-keyed). The
 * marker instead drives the *application-layer* guards (State providers /
 * processors, BCK-03); TenantOwnedInterfaceCompletenessTest keeps the marker
 * and the column set in lockstep so those guards stay complete.
 *
 * The filter is activated per-request by TenantFilterListener.
 */
class TenantFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        // Only apply to entities that actually have a club_id column.
        if (!$this->hasClubIdColumn($targetEntity)) {
            return '';
        }

        // getParameter() returns the SQL-escaped literal (e.g. '550e8400-…').
        return \sprintf('%s.club_id = %s', $targetTableAlias, $this->getParameter('club_id'));
    }

    /** @phpstan-ignore missingType.generics */
    private function hasClubIdColumn(ClassMetadata $targetEntity): bool
    {
        foreach ($targetEntity->getFieldNames() as $fieldName) {
            if ('club_id' === $targetEntity->getColumnName($fieldName)) {
                return true;
            }
        }

        return false;
    }
}
