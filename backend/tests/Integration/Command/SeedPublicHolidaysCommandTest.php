<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\PublicHoliday;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[Group('phase1')]
#[Group('integration')]
final class SeedPublicHolidaysCommandTest extends KernelTestCase
{
    public function testSeedPopulatesNationalHolidaysAndIsIdempotent(): void
    {
        $kernel = self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('app:public-holidays:seed'));

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $firstCount = $this->rowCount($em);
        self::assertGreaterThan(0, $firstCount);

        // Bastille Day (the summer férié the calendar was missing) must be present.
        $bastille = $em->getRepository(PublicHoliday::class)->findOneBy(['zone' => PublicHoliday::NATIONAL, 'date' => new DateTimeImmutable('2026-07-14')]);
        self::assertNotNull($bastille);
        self::assertSame('Fête nationale', $bastille->getLabel());

        // Re-seed: upsert by (zone, date) → same row count, no duplicates.
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertSame($firstCount, $this->rowCount($em));
    }

    private function rowCount(EntityManagerInterface $em): int
    {
        return (int) $em->createQuery('SELECT COUNT(h.id) FROM ' . PublicHoliday::class . ' h')->getSingleScalarResult();
    }
}
