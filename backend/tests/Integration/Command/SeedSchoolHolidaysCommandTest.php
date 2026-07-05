<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\SchoolHolidayPeriod;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[Group('phase1')]
#[Group('integration')]
final class SeedSchoolHolidaysCommandTest extends KernelTestCase
{
    public function testSeedIsIdempotent(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:school-holidays:seed'));

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $firstCount = $this->rowCount($em);
        self::assertGreaterThan(0, $firstCount);

        // Re-seed: upsert by natural key → same row count, no duplicates.
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertSame($firstCount, $this->rowCount($em));
    }

    private function rowCount(EntityManagerInterface $em): int
    {
        return (int) $em->createQuery('SELECT COUNT(h.id) FROM ' . SchoolHolidayPeriod::class . ' h')->getSingleScalarResult();
    }
}
