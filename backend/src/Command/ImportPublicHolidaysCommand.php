<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PublicHoliday;
use App\Repository\PublicHolidayRepository;
use App\Service\PublicHolidayMapper;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Imports French public holidays (jours fériés) from the official etalab API
 * (calendrier.api.gouv.fr/jours-feries/{zone}.json), current calendar year + N+1.
 * Métropole fériés → zone NATIONAL; each DOM/TOM file diffed against métropole →
 * its territory-specific extras tagged with the territory zone code. Idempotent:
 * upsert by the natural key (zone, date). Display-only — never feeds the solver.
 * Run manually today; a yearly cron / superadmin trigger comes later.
 * See specs/evolution/roadmap.md §2.
 */
#[AsCommand(
    name: 'app:public-holidays:import',
    description: 'Import public holidays (jours fériés) from the official etalab API (idempotent).',
)]
final class ImportPublicHolidaysCommand extends Command
{
    private const DEFAULT_BASE_URL = 'https://calendrier.api.gouv.fr';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly PublicHolidayRepository $repository,
        private readonly PublicHolidayMapper $mapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'etalab API base URL', self::DEFAULT_BASE_URL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $baseUrl = rtrim((string) $input->getOption('base-url'), '/');
        [$fromYear, $toYear] = $this->mapper->yearWindow(new DateTimeImmutable);

        try {
            $metropole = $this->fetchZoneFile($baseUrl, 'metropole');
        } catch (HttpExceptionInterface $e) {
            $io->error(\sprintf('Failed to fetch the métropole calendar: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        /** @var list<array{zone: string, date: DateTimeImmutable, label: string}> $rows */
        $rows = [];
        foreach ($this->mapper->nationalHolidays($metropole, $fromYear, $toYear) as $row) {
            $rows[] = ['zone' => PublicHoliday::NATIONAL, ...$row];
        }

        $skippedTerritories = 0;
        foreach (PublicHolidayMapper::TERRITORY_FILE_TO_ZONE as $file => $zoneCode) {
            try {
                $territory = $this->fetchZoneFile($baseUrl, $file);
            } catch (HttpExceptionInterface $e) {
                // etalab may not publish every territory every year — warn + skip.
                $io->warning(\sprintf('Skipped territory "%s": %s', $file, $e->getMessage()));
                ++$skippedTerritories;

                continue;
            }
            foreach ($this->mapper->territoryExtras($metropole, $territory, $fromYear, $toYear) as $row) {
                $rows[] = ['zone' => $zoneCode, ...$row];
            }
        }

        $created = 0;
        $updated = 0;
        foreach ($rows as $row) {
            $entity = $this->repository->findOneByNaturalKey($row['zone'], $row['date']);
            if (null === $entity) {
                $entity = new PublicHoliday;
                $entity->setZone($row['zone']);
                $entity->setDate($row['date']);
                $this->entityManager->persist($entity);
                ++$created;
            } else {
                ++$updated;
            }
            $entity->setLabel($row['label']);
        }

        $this->entityManager->flush();

        $io->success(\sprintf(
            'Public holidays imported (%d–%d): %d created, %d updated, %d territory file(s) skipped.',
            $fromYear,
            $toYear,
            $created,
            $updated,
            $skippedTerritories,
        ));

        return Command::SUCCESS;
    }

    /**
     * Fetches one etalab zone file → flat map { "YYYY-MM-DD": label }.
     *
     * @return array<string, string>
     */
    private function fetchZoneFile(string $baseUrl, string $zoneFile): array
    {
        $response = $this->httpClient->request('GET', \sprintf('%s/jours-feries/%s.json', $baseUrl, $zoneFile), [
            'timeout' => 30,
        ]);

        /** @var array<string, string> $data */
        $data = $response->toArray();

        return $data;
    }
}
