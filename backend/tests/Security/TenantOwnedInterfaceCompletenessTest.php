<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\TenantOwnedInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * BCK-03 meta-guard. The application-layer tenant guards (State providers /
 * processors) key on the App\Entity\TenantOwnedInterface marker instead of
 * duck-typed method_exists('getClubId'). That is only sound if the marker set
 * and the `club_id`-column set are exactly the same:
 *
 * - every entity with a club_id column MUST implement the interface, else the
 *   app-layer guard silently skips it (a tenant entity editable/deletable
 *   cross-club at the app layer — the DB-level TenantFilter/RLS still scope it,
 *   but defence-in-depth is lost);
 * - every implementer MUST own a club_id column, else the marker is a lie
 *   (getClubId() would have nothing to return / global rows masquerade as
 *   tenant-owned).
 *
 * This enumerates Doctrine metadata and asserts the two sets are identical.
 */
#[Group('phase1')]
final class TenantOwnedInterfaceCompletenessTest extends KernelTestCase
{
    public function testMarkerSetAndClubIdColumnSetAreIdentical(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $withColumnMissingMarker = [];
        $withMarkerMissingColumn = [];

        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            $class = $metadata->getName();
            $hasColumn = $this->hasClubIdColumn($metadata);
            $hasMarker = is_a($class, TenantOwnedInterface::class, true);

            if ($hasColumn && !$hasMarker) {
                $withColumnMissingMarker[] = $class;
            }
            if ($hasMarker && !$hasColumn) {
                $withMarkerMissingColumn[] = $class;
            }
        }

        self::assertSame(
            [],
            $withColumnMissingMarker,
            'Entities with a club_id column MUST implement App\\Entity\\TenantOwnedInterface '
            . '(else the app-layer tenant guard skips them): ' . implode(', ', $withColumnMissingMarker),
        );
        self::assertSame(
            [],
            $withMarkerMissingColumn,
            'Entities implementing App\\Entity\\TenantOwnedInterface MUST own a club_id column: '
            . implode(', ', $withMarkerMissingColumn),
        );
    }

    /** @param ClassMetadata<object> $metadata */
    private function hasClubIdColumn(ClassMetadata $metadata): bool
    {
        foreach ($metadata->getFieldNames() as $fieldName) {
            if ('club_id' === $metadata->getColumnName($fieldName)) {
                return true;
            }
        }

        return false;
    }
}
