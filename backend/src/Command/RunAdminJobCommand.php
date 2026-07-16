<?php

declare(strict_types=1);

namespace App\Command;

use App\AdminJob\AdminJobAlreadyRunning;
use App\AdminJob\AdminJobCatalog;
use App\AdminJob\AdminJobDefinition;
use App\AdminJob\AdminJobRunner;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'app:jobs:run', description: 'Run one allowlisted operational job with durable status tracking.')]
final class RunAdminJobCommand extends Command
{
    public function __construct(
        private readonly AdminJobCatalog $catalog,
        private readonly AdminJobRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('job', InputArgument::REQUIRED, 'Allowlisted job key.')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Execution source: cli or scheduled.', 'cli')
            ->addOption('scheduled-for', null, InputOption::VALUE_REQUIRED, 'Scheduled slot in ISO-8601 format.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jobKey = (string) $input->getArgument('job');
        $source = (string) $input->getOption('source');
        $scheduledForRaw = $input->getOption('scheduled-for');
        if (!\in_array($source, ['cli', 'scheduled'], true)) {
            $io->error('Invalid --source: expected cli or scheduled.');

            return Command::INVALID;
        }
        if (('scheduled' === $source) !== \is_string($scheduledForRaw)) {
            $io->error('--scheduled-for is required only when --source=scheduled.');

            return Command::INVALID;
        }
        $scheduledFor = \is_string($scheduledForRaw) ? DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $scheduledForRaw) : null;
        if (false === $scheduledFor) {
            $io->error('Invalid --scheduled-for: expected ISO-8601.');

            return Command::INVALID;
        }

        $definition = $this->catalog->find($jobKey);
        if (!$definition instanceof AdminJobDefinition) {
            $io->error(\sprintf('Unknown job "%s". Allowed keys: %s.', $jobKey, implode(', ', array_map(static fn (AdminJobDefinition $job): string => $job->key, $this->catalog->all()))));

            return Command::INVALID;
        }

        $application = $this->getApplication();
        if (!$application instanceof Application) {
            $io->error('The console application is unavailable.');

            return Command::FAILURE;
        }

        try {
            return $this->runner->run($definition, $source, null, function () use ($application, $definition, $output): int {
                $target = $application->find($definition->command);
                $targetInput = new ArrayInput($definition->arguments);
                $targetInput->setInteractive(false);

                return $target->run($targetInput, $output);
            }, $scheduledFor);
        } catch (AdminJobAlreadyRunning $error) {
            $io->warning($error->getMessage());

            return Command::FAILURE;
        } catch (Throwable $error) {
            $io->error(\sprintf('Job "%s" failed with an unexpected error: %s', $jobKey, $error->getMessage()));

            return Command::FAILURE;
        }
    }
}
