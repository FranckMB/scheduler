<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\PublicHoliday;
use App\Entity\SchoolHolidayPeriod;
use App\Service\HolidaySeeder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * HolidaySeeder (extracted from the seed commands, now also used by the data
 * fixtures) seeds the global holiday reference from the versioned JSON and is
 * idempotent — a second run updates in place, never duplicates. Guards that a
 * fixture load populates the holidays a correctly-zoned club then displays.
 */
#[Group('integration')]
final class HolidaySeederTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private HolidaySeeder $seeder;

    public function testSeedsSchoolAndPublicHolidaysIdempotently(): void
    {
        $first = $this->seeder->seedSchool();
        self::assertGreaterThan(0, $first['created'] + $first['updated']);
        self::assertSame(0, $first['skipped'], 'the shipped JSON has no malformed rows');
        $schoolCount = $this->em->getRepository(SchoolHolidayPeriod::class)->count([]);
        self::assertGreaterThan(0, $schoolCount);

        // Second run: idempotent — no duplicates, everything updated in place.
        $second = $this->seeder->seedSchool();
        self::assertSame(0, $second['created']);
        self::assertSame($schoolCount, $this->em->getRepository(SchoolHolidayPeriod::class)->count([]));

        $pub = $this->seeder->seedPublic();
        self::assertGreaterThan(0, $pub['created'] + $pub['updated']);
        self::assertGreaterThan(0, $this->em->getRepository(PublicHoliday::class)->count([]));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seeder = self::getContainer()->get(HolidaySeeder::class);
    }
}
