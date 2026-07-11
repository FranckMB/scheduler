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

    public function testSummerHolidaysAreSeeded(): void
    {
        // Vacances d'Été affichées en bande info dans le cockpit (demande
        // utilisateur) : le seed offline doit les inclure pour toutes les zones.
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        new CommandTester(new Application(self::$kernel)->find('app:school-holidays:seed'))->execute([]);

        $summer = $em->getRepository(SchoolHolidayPeriod::class)->findBy(['holidayType' => 'ete']);
        self::assertNotEmpty($summer, 'les vacances d\'été sont seedées');
        $zones = array_unique(array_map(static fn (SchoolHolidayPeriod $h): string => $h->getZone(), $summer));
        self::assertContains('A', $zones);
        self::assertContains('CORSE', $zones);
        foreach ($summer as $h) {
            self::assertSame('Vacances d\'Été', $h->getLabel());
            self::assertSame(7, (int) $h->getStartDate()->format('n'), 'l\'été commence en juillet');
        }
    }

    private function rowCount(EntityManagerInterface $em): int
    {
        return (int) $em->createQuery('SELECT COUNT(h.id) FROM ' . SchoolHolidayPeriod::class . ' h')->getSingleScalarResult();
    }
}
