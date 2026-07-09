<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PublicHoliday;
use App\Repository\PublicHolidayRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds the national public-holiday (jours fériés) reference from a versioned JSON —
 * OFFLINE, unlike app:public-holidays:import which fetches calendrier.api.gouv.fr and
 * so can't run in fixtures/CI. Métropole fériés → zone NATIONAL (apply to all clubs).
 * Idempotent: upsert by the natural key (zone, date). Display-only — never feeds the
 * solver. Run once a year when the next year's dates are added to the JSON.
 */
#[AsCommand(
    name: 'app:public-holidays:seed',
    description: 'Seed/refresh national public-holiday reference from data/public-holidays.fr-national.json (offline, idempotent).',
)]
final class SeedPublicHolidaysCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PublicHolidayRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the JSON source', \dirname(__DIR__, 2) . '/data/public-holidays.fr-national.json');
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

        /** @var array{holidays?: array<string, string>} $data */
        $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        $holidays = $data['holidays'] ?? [];

        $created = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($holidays as $dateStr => $label) {
            $date = $this->parseDate((string) $dateStr);
            if (null === $date || '' === (string) $label) {
                ++$skipped;

                continue;
            }

            $entity = $this->repository->findOneByNaturalKey(PublicHoliday::NATIONAL, $date);
            if (null === $entity) {
                $entity = (new PublicHoliday)->setZone(PublicHoliday::NATIONAL)->setDate($date);
                $this->entityManager->persist($entity);
                ++$created;
            } else {
                ++$updated;
            }

            $entity->setLabel((string) $label);
        }

        $this->entityManager->flush();

        $io->success(\sprintf('Public holidays seeded: %d created, %d updated%s.', $created, $updated, $skipped > 0 ? \sprintf(', %d skipped', $skipped) : ''));

        return Command::SUCCESS;
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }
}
