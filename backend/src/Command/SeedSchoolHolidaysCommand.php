<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SchoolHolidayPeriod;
use App\Repository\SchoolHolidayPeriodRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds the national school-holiday reference from a versioned JSON — no network
 * at runtime (accueil-cockpit-temporel.md §4bis). Idempotent: upsert by the
 * natural key (zone, holidayType, schoolYear). Run once a year when the official
 * calendar for the next year is published.
 */
#[AsCommand(
    name: 'app:school-holidays:seed',
    description: 'Seed/refresh school-holiday reference data from data/school-holidays.fr-metropole.json (idempotent).',
)]
final class SeedSchoolHolidaysCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchoolHolidayPeriodRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the JSON source', \dirname(__DIR__, 2) . '/data/school-holidays.fr-metropole.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getOption('file');

        if (!is_file($file)) {
            $io->error(\sprintf('Source file not found: %s', $file));

            return Command::FAILURE;
        }

        $raw = file_get_contents($file);
        if (false === $raw) {
            $io->error('Could not read the source file.');

            return Command::FAILURE;
        }

        /** @var array{periods?: list<array<string, string>>} $data */
        $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        $periods = $data['periods'] ?? [];

        $created = 0;
        $updated = 0;
        foreach ($periods as $row) {
            $zone = $row['zone'] ?? '';
            $type = $row['holidayType'] ?? '';
            $year = $row['schoolYear'] ?? '';
            if ('' === $zone || '' === $type || '' === $year) {
                continue;
            }

            $entity = $this->repository->findOneByNaturalKey($zone, $type, $year);
            if (null === $entity) {
                $entity = new SchoolHolidayPeriod;
                $entity->setZone($zone);
                $entity->setHolidayType($type);
                $entity->setSchoolYear($year);
                $this->entityManager->persist($entity);
                ++$created;
            } else {
                ++$updated;
            }

            $entity->setLabel($row['label'] ?? '');
            $entity->setStartDate(new DateTimeImmutable($row['startDate'] ?? 'now'));
            $entity->setEndDate(new DateTimeImmutable($row['endDate'] ?? 'now'));
        }

        $this->entityManager->flush();

        $io->success(\sprintf('School holidays seeded: %d created, %d updated.', $created, $updated));

        return Command::SUCCESS;
    }
}
