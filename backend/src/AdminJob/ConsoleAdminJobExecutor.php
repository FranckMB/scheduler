<?php

declare(strict_types=1);

namespace App\AdminJob;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\KernelInterface;

/** Runs a fixed catalog command from the super-admin HTTP entry point. */
final readonly class ConsoleAdminJobExecutor implements AdminJobExecutorInterface
{
    public function __construct(
        private KernelInterface $kernel,
        private AdminJobRunner $runner,
    ) {}

    public function run(AdminJobDefinition $definition, string $superAdminId): int
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        return $this->runner->run($definition, 'superadmin', $superAdminId, static function () use ($application, $definition): int {
            $command = $application->find($definition->command);
            $input = new ArrayInput($definition->arguments);
            $input->setInteractive(false);

            return $command->run($input, new NullOutput);
        });
    }
}
