<?php

declare(strict_types=1);

namespace App\Command;

use App\AdminJob\AdminJobCatalog;
use App\AdminJob\AdminJobRunStore;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:jobs:run-due', description: 'Run every allowlisted operational job whose scheduled slot is due.')]
final class RunDueAdminJobsCommand extends Command
{
    public function __construct(private readonly AdminJobCatalog $catalog, private readonly AdminJobRunStore $store, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $application = $this->getApplication();
        if (!$application instanceof Application) {
            return Command::FAILURE;
        }

        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $result = Command::SUCCESS;
        foreach ($this->catalog->all() as $definition) {
            $dueAt = $definition->schedule->nextDueAt($now, $this->store->latestScheduledFor($definition->key));
            if ($dueAt > $now) {
                continue;
            }
            $exitCode = $application->find('app:jobs:run')->run(new ArrayInput([
                'job' => $definition->key,
                '--source' => 'scheduled',
                '--scheduled-for' => $dueAt->format(DateTimeInterface::ATOM),
                '--no-interaction' => true,
            ]), $output);
            if (Command::SUCCESS !== $exitCode) {
                $result = Command::FAILURE;
            }
        }

        return $result;
    }
}
