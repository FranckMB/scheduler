<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\LeagueMatchWindow;
use App\Repository\LeagueMatchWindowRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds the federation league-match-window catalog from a versioned JSON — no
 * network at runtime (spec gestion-matchs §6bis). Idempotent: upsert by the
 * natural key (league, category, level, gender, dayOfWeek, kickoffMin). The
 * AURA seed is the default base inherited by every club.
 */
#[AsCommand(
    name: 'app:league-windows:seed',
    description: 'Seed/refresh the league-match-window catalog from data/league-match-windows.aura.json (idempotent).',
)]
final class SeedLeagueWindowsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LeagueMatchWindowRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the JSON source', \dirname(__DIR__, 2) . '/data/league-match-windows.aura.json');
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

        /** @var array{windows?: list<array<string, mixed>>} $data */
        $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        $windows = $data['windows'] ?? [];

        $created = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($windows as $row) {
            $league = \is_string($row['league'] ?? null) ? $row['league'] : '';
            $category = \is_string($row['category'] ?? null) ? $row['category'] : '';
            $level = \is_string($row['level'] ?? null) ? $row['level'] : '';
            $gender = \is_string($row['gender'] ?? null) ? $row['gender'] : null;
            $dayOfWeek = \is_int($row['dayOfWeek'] ?? null) ? $row['dayOfWeek'] : 0;
            $min = $this->parseTime($row['kickoffMin'] ?? null);
            $max = $this->parseTime($row['kickoffMax'] ?? null);

            if ('' === $league || '' === $category || '' === $level || $dayOfWeek < 1 || $dayOfWeek > 7 || null === $min || null === $max) {
                $io->warning(\sprintf('Skipped malformed row: %s', json_encode($row)));
                ++$skipped;

                continue;
            }

            $entity = $this->repository->findOneByNaturalKey($league, $category, $level, $gender, $dayOfWeek, $min);
            if (null === $entity) {
                $entity = new LeagueMatchWindow;
                $entity->setLeague($league);
                $entity->setCategory($category);
                $entity->setLevel($level);
                $entity->setGender($gender);
                $entity->setDayOfWeek($dayOfWeek);
                $entity->setKickoffMin($min);
                $this->entityManager->persist($entity);
                ++$created;
            } else {
                ++$updated;
            }

            $entity->setKickoffMax($max);
        }

        $this->entityManager->flush();

        if ($skipped > 0) {
            $io->warning(\sprintf('%d malformed row(s) skipped.', $skipped));

            return Command::FAILURE;
        }

        $io->success(\sprintf('League match windows seeded: %d created, %d updated.', $created, $updated));

        return Command::SUCCESS;
    }

    private function parseTime(mixed $value): ?DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match('/^\d{2}:\d{2}$/', $value)) {
            return null;
        }

        $time = DateTimeImmutable::createFromFormat('!H:i', $value);

        return false === $time ? null : $time;
    }
}
