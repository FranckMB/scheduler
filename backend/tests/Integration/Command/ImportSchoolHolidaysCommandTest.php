<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ImportSchoolHolidaysCommand;
use App\Repository\SchoolHolidayPeriodRepository;
use App\Service\FrenchSchoolCalendarMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Drives app:school-holidays:import against a MockHttpClient (no network):
 * exercises pagination, description→type + zone mapping, the vacation filter,
 * dedup by zone, upsert and idempotency. Uses a far-future school year to avoid
 * colliding with the seed/other fixtures. See specs/evolution/roadmap.md §2.
 */
#[Group('phase1')]
#[Group('integration')]
final class ImportSchoolHolidaysCommandTest extends KernelTestCase
{
    private const YEAR = '2099-2100';

    public function testImportMapsFiltersDedupsAndIsIdempotent(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $repository = $container->get(SchoolHolidayPeriodRepository::class);
        $mapper = $container->get(FrenchSchoolCalendarMapper::class);

        $command = new ImportSchoolHolidaysCommand(
            new MockHttpClient($this->pagedResponses(...)),
            $em,
            $repository,
            $mapper,
        );
        $application = new Application(self::$kernel);
        $application->add($command);
        $tester = new CommandTester($application->find('app:school-holidays:import'));

        $tester->execute(['--page-size' => '2']);
        $tester->assertCommandIsSuccessful();

        // Kept: Zone A toussaint (two académies deduped to one) + Corse toussaint.
        $zoneA = $repository->findOneByNaturalKey('A', 'toussaint', self::YEAR);
        $corse = $repository->findOneByNaturalKey('CORSE', 'toussaint', self::YEAR);
        self::assertNotNull($zoneA);
        self::assertNotNull($corse);
        self::assertSame('CORSE', $corse->getZone());
        // Stored end = veille de rentrée (API return date − 1 day).
        self::assertSame('2099-11-02', $zoneA->getEndDate()->format('Y-m-d'));

        // Rejected: the Ascension bridge (label "Pont …") was never imported.
        self::assertNull($repository->findOneByNaturalKey('A', 'pont_de_l_ascension', self::YEAR));

        $firstIds = [$zoneA->getId(), $corse->getId()];

        // Re-run: upsert by natural key → same rows, no duplicates.
        $tester->execute(['--page-size' => '2']);
        $tester->assertCommandIsSuccessful();
        $em->clear();

        self::assertSame(2, $this->countForYear($em));
        self::assertSame(
            $firstIds,
            [
                $repository->findOneByNaturalKey('A', 'toussaint', self::YEAR)?->getId(),
                $repository->findOneByNaturalKey('CORSE', 'toussaint', self::YEAR)?->getId(),
            ],
        );
    }

    /**
     * Serves the fixture in pages of 2 based on the `offset` query param, so the
     * command's pagination loop terminates when the last page is short.
     *
     * @param array<string, mixed> $options
     */
    private function pagedResponses(string $method, string $url, array $options = []): MockResponse
    {
        $pages = [
            0 => [
                $this->record('Vacances de la Toussaint', 'Zone A', '2099-10-19', '2099-11-03'),
                $this->record('Vacances de la Toussaint', 'Zone A', '2099-10-19', '2099-11-03'), // dup académie
            ],
            2 => [
                $this->record('Pont de l\'Ascension', 'Zone A', '2099-05-13', '2099-05-17'), // rejected
                $this->record('Vacances de la Toussaint', 'Corse', '2099-10-19', '2099-11-03'),
            ],
            4 => [
                $this->record('Vacances de la Toussaint', 'Zone Z', '2099-10-19', '2099-11-03'), // unknown zone
            ],
        ];

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $offset = (int) ($query['offset'] ?? 0);
        $results = $pages[$offset] ?? [];

        return new MockResponse(
            (string) json_encode(['total_count' => 5, 'results' => $results]),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    /**
     * @return array<string, string>
     */
    private function record(string $description, string $zones, string $start, string $end): array
    {
        return [
            'description' => $description,
            'zones' => $zones,
            'annee_scolaire' => self::YEAR,
            'start_date' => $start . 'T22:00:00+00:00',
            'end_date' => $end . 'T23:00:00+00:00',
        ];
    }

    private function countForYear(EntityManagerInterface $em): int
    {
        return (int) $em->createQuery(
            'SELECT COUNT(h.id) FROM App\Entity\SchoolHolidayPeriod h WHERE h.schoolYear = :y',
        )->setParameter('y', self::YEAR)->getSingleScalarResult();
    }
}
