<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ImportPublicHolidaysCommand;
use App\Entity\PublicHoliday;
use App\Repository\PublicHolidayRepository;
use App\Service\PublicHolidayMapper;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Drives app:public-holidays:import against a MockHttpClient (no network): one file
 * per zone, métropole → NATIONAL, territory diff → extras, a 404 territory skipped,
 * out-of-window years excluded, upsert idempotent. Uses far-future dates in the
 * computed window to avoid colliding with other fixtures.
 */
#[Group('phase1')]
#[Group('integration')]
final class ImportPublicHolidaysCommandTest extends KernelTestCase
{
    public function testImportDiffsTerritoriesSkips404AndIsIdempotent(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $repository = $container->get(PublicHolidayRepository::class);
        $mapper = $container->get(PublicHolidayMapper::class);

        [$fromYear] = $mapper->yearWindow(new DateTimeImmutable);
        $natDate = \sprintf('%d-01-01', $fromYear);
        $gpDate = \sprintf('%d-05-27', $fromYear);
        $outDate = \sprintf('%d-01-01', $fromYear + 5); // out of the [Y, Y+1] window

        $command = new ImportPublicHolidaysCommand(
            new MockHttpClient(fn (string $m, string $url): MockResponse => $this->routeFixture($url, $natDate, $gpDate, $outDate)),
            $em,
            $repository,
            $mapper,
        );
        $application = new Application(self::$kernel);
        $application->add($command);
        $tester = new CommandTester($application->find('app:public-holidays:import'));

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        // National kept, Guadeloupe extra kept under its zone, out-of-window dropped.
        $national = $repository->findOneByNaturalKey(PublicHoliday::NATIONAL, new DateTimeImmutable($natDate));
        $guadeloupe = $repository->findOneByNaturalKey('GUADELOUPE', new DateTimeImmutable($gpDate));
        self::assertNotNull($national);
        self::assertNotNull($guadeloupe);
        self::assertTrue($national->isNational());
        self::assertFalse($guadeloupe->isNational());
        self::assertNull($repository->findOneByNaturalKey(PublicHoliday::NATIONAL, new DateTimeImmutable($outDate)));
        // A métropole date is NOT duplicated under the territory zone.
        self::assertNull($repository->findOneByNaturalKey('GUADELOUPE', new DateTimeImmutable($natDate)));
        // The 404 territory (Wallis) produced nothing.
        self::assertNull($repository->findOneByNaturalKey('WALLIS_FUTUNA', new DateTimeImmutable($gpDate)));

        $firstIds = [$national->getId(), $guadeloupe->getId()];

        // Re-run: upsert by (zone, date) → same rows, no duplicates.
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $em->clear();

        self::assertSame(
            $firstIds,
            [
                $repository->findOneByNaturalKey(PublicHoliday::NATIONAL, new DateTimeImmutable($natDate))?->getId(),
                $repository->findOneByNaturalKey('GUADELOUPE', new DateTimeImmutable($gpDate))?->getId(),
            ],
        );
    }

    private function routeFixture(string $url, string $natDate, string $gpDate, string $outDate): MockResponse
    {
        $metropole = [
            $natDate => '1er janvier',
            $outDate => '1er janvier', // out of window → dropped by the mapper
        ];

        if (str_contains($url, '/metropole.json')) {
            return $this->json($metropole);
        }
        if (str_contains($url, '/guadeloupe.json')) {
            return $this->json([...$metropole, $gpDate => 'Abolition de l\'esclavage']);
        }
        if (str_contains($url, '/wallis.json')) {
            return new MockResponse('Not found', ['http_code' => 404]);
        }

        // Every other territory = métropole only (no extras).
        return $this->json($metropole);
    }

    /**
     * @param array<string, string> $map
     */
    private function json(array $map): MockResponse
    {
        return new MockResponse(
            (string) json_encode($map),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }
}
