<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SchoolHolidayPeriod;
use App\Repository\SchoolHolidayPeriodRepository;
use App\Service\FrenchSchoolCalendarMapper;
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
 * Imports the national school-holiday reference from the official Éducation
 * nationale Open Data API (ODS dataset fr-en-calendrier-scolaire), for the
 * current school year + the next one, across all 13 zones (A/B/C, Corse, DOM/TOM).
 * Idempotent: upsert by the natural key (zone, holidayType, schoolYear), like
 * app:school-holidays:seed — which stays as the offline JSON fallback.
 * Scheduled quarterly by the operational job runner; remains manually callable.
 * See specs/evolution/roadmap.md §2.
 */
#[AsCommand(
    name: 'app:school-holidays:import',
    description: 'Import school-holiday reference data from the official Éducation nationale ODS API (idempotent).',
)]
final class ImportSchoolHolidaysCommand extends Command
{
    private const DEFAULT_BASE_URL = 'https://data.education.gouv.fr';
    private const RECORDS_PATH = '/api/explore/v2.1/catalog/datasets/fr-en-calendrier-scolaire/records';
    private const ODS_MAX_WINDOW = 10000; // ODS caps offset + limit at 10000.

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly SchoolHolidayPeriodRepository $repository,
        private readonly FrenchSchoolCalendarMapper $mapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'ODS API base URL', self::DEFAULT_BASE_URL);
        $this->addOption('page-size', null, InputOption::VALUE_REQUIRED, 'Records fetched per API page (max 100)', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $baseUrl = rtrim((string) $input->getOption('base-url'), '/');
        $pageSize = max(1, min(100, (int) $input->getOption('page-size')));
        [$currentYear, $nextYear] = $this->mapper->schoolYearWindow(new DateTimeImmutable);
        // Term vacations (Toussaint/Noël/…) carry population "-"; summer is split
        // "Élèves" (kept) vs "Enseignants" (dropped). Both buckets are pupil-facing.
        $where = \sprintf(
            '(population="-" or population="Élèves") and (annee_scolaire="%s" or annee_scolaire="%s")',
            $currentYear,
            $nextYear,
        );

        try {
            $records = $this->fetchAll($baseUrl, $where, $pageSize);
        } catch (HttpExceptionInterface $e) {
            $io->error(\sprintf('ODS API request failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->text(\sprintf('Fetched %d record(s) for %s / %s.', \count($records), $currentYear, $nextYear));

        /** @var array<string, array{zone: string, type: string, year: string, label: string, start: DateTimeImmutable, end: DateTimeImmutable}> $deduped */
        $deduped = [];
        $skipped = 0;
        foreach ($records as $record) {
            $prepared = $this->prepare($record);
            if (null === $prepared) {
                ++$skipped;

                continue;
            }
            // Dedup: the API returns one row per académie, many share a zone.
            $key = $prepared['zone'] . '|' . $prepared['type'] . '|' . $prepared['year'];
            $deduped[$key] ??= $prepared;
        }

        $created = 0;
        $updated = 0;
        foreach ($deduped as $row) {
            $entity = $this->repository->findOneByNaturalKey($row['zone'], $row['type'], $row['year']);
            if (null === $entity) {
                $entity = new SchoolHolidayPeriod;
                $entity->setZone($row['zone']);
                $entity->setHolidayType($row['type']);
                $entity->setSchoolYear($row['year']);
                $this->entityManager->persist($entity);
                ++$created;
            } else {
                ++$updated;
            }
            $entity->setLabel($row['label']);
            $entity->setStartDate($row['start']);
            $entity->setEndDate($row['end']);
        }

        $this->entityManager->flush();

        $io->success(\sprintf(
            'School holidays imported: %d created, %d updated, %d skipped (non-vacation/unmapped).',
            $created,
            $updated,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    /**
     * Pages through the ODS `records` endpoint until exhausted.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $baseUrl, string $where, int $pageSize): array
    {
        $records = [];
        $offset = 0;
        $total = null;
        do {
            $response = $this->httpClient->request('GET', $baseUrl . self::RECORDS_PATH, [
                'query' => [
                    'where' => $where,
                    'select' => 'description,start_date,end_date,zones,annee_scolaire',
                    'limit' => $pageSize,
                    'offset' => $offset,
                ],
                'timeout' => 30,
            ]);

            /** @var array{results?: list<array<string, mixed>>, total_count?: int} $data */
            $data = $response->toArray();
            $results = $data['results'] ?? [];
            $total ??= (int) ($data['total_count'] ?? 0);
            foreach ($results as $result) {
                $records[] = $result;
            }

            $offset += $pageSize;
            // Stop as soon as we have paged past total_count — no extra empty
            // request when the record count is an exact multiple of the page size.
        } while ([] !== $results && $offset < min($total, self::ODS_MAX_WINDOW));

        return $records;
    }

    /**
     * Validates + maps one ODS record; returns null when it is not an importable
     * vacation (unknown zone, non-vacation span, or malformed dates).
     *
     * @param array<string, mixed> $record
     *
     * @return array{zone: string, type: string, year: string, label: string, start: DateTimeImmutable, end: DateTimeImmutable}|null
     */
    private function prepare(array $record): ?array
    {
        $label = \is_string($record['description'] ?? null) ? trim((string) $record['description']) : '';
        $rawZone = \is_string($record['zones'] ?? null) ? (string) $record['zones'] : '';
        $year = \is_string($record['annee_scolaire'] ?? null) ? (string) $record['annee_scolaire'] : '';
        $start = $this->parseApiDate($record['start_date'] ?? null);
        $endExclusive = $this->parseApiDate($record['end_date'] ?? null);

        if ('' === $label || '' === $year || null === $start || null === $endExclusive) {
            return null;
        }

        $zone = $this->mapper->mapZone($rawZone);
        if (null === $zone) {
            return null;
        }

        if (!$this->mapper->isVacation($label, $start, $endExclusive)) {
            return null;
        }

        $type = $this->mapper->mapHolidayType($label);
        if ('' === $type) {
            return null;
        }

        return [
            'zone' => $zone,
            'type' => $type,
            'year' => $year,
            'label' => $label,
            // Store the last day off (veille de rentrée) to match the JSON seed
            // convention; the API end_date is the return-to-school day.
            'start' => $start,
            'end' => $endExclusive->modify('-1 day'),
        ];
    }

    /**
     * The ODS dates are ISO-8601 with a UTC offset that encodes Paris local
     * midnight; the leading Y-m-d IS the intended calendar day (matches the seed
     * JSON), so we read it directly rather than shifting timezones.
     */
    private function parseApiDate(mixed $value): ?DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $m[1]);
        $errors = DateTimeImmutable::getLastErrors();
        if (false === $date || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }
}
