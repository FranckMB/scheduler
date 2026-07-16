<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\AdminJob\AdminJobCatalog;
use App\AdminJob\AdminJobDefinition;
use App\AdminJob\AdminJobRunner;
use App\AdminJob\AdminJobRunStore;
use App\Command\RunAdminJobCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[Group('phase1')]
#[Group('integration')]
final class RunAdminJobCommandTest extends KernelTestCase
{
    private Connection $admin;

    public function testSuccessfulRunIsPersistedWithoutCommandOutput(): void
    {
        $tester = $this->testerFor('test:job:success', Command::SUCCESS);

        self::assertSame(Command::SUCCESS, $tester->execute(['job' => 'test-job', '--source' => 'scheduled']));

        $run = $this->singleRun();
        self::assertSame('test-job', $run['job_key']);
        self::assertSame('test:job:success', $run['command_name']);
        self::assertSame('scheduled', $run['source']);
        self::assertSame('succeeded', $run['status']);
        self::assertSame(0, (int) $run['exit_code']);
        self::assertGreaterThanOrEqual(0, (int) $run['duration_ms']);
        self::assertNotNull($run['finished_at']);
        self::assertArrayNotHasKey('output', $run);
        self::assertArrayNotHasKey('error_message', $run);
    }

    public function testNonZeroAndUnexpectedFailuresArePersisted(): void
    {
        $failed = $this->testerFor('test:job:failure', 7);
        self::assertSame(7, $failed->execute(['job' => 'test-job']));
        self::assertSame(['status' => 'failed', 'exit_code' => 7], $this->admin->fetchAssociative('SELECT status, exit_code FROM admin_job_run'));

        $this->admin->executeStatement('DELETE FROM admin_job_run');
        $throwing = $this->testerFor('test:job:throws', new RuntimeException('sensitive internal detail'));
        self::assertSame(Command::FAILURE, $throwing->execute(['job' => 'test-job']));
        self::assertSame(['status' => 'failed', 'exit_code' => 1], $this->admin->fetchAssociative('SELECT status, exit_code FROM admin_job_run'));
    }

    public function testStaleRunningAttemptIsInterruptedBeforeTheNextRun(): void
    {
        $staleId = '00000000-0000-4000-8000-000000000001';
        $this->admin->insert('admin_job_run', [
            'id' => $staleId,
            'job_key' => 'test-job',
            'command_name' => 'test:job:old',
            'source' => 'scheduled',
            'status' => 'running',
            'started_at' => '2026-07-16 08:00:00+00',
        ]);

        $tester = $this->testerFor('test:job:success', Command::SUCCESS);
        self::assertSame(Command::SUCCESS, $tester->execute(['job' => 'test-job']));

        self::assertSame('interrupted', $this->admin->fetchOne('SELECT status FROM admin_job_run WHERE id = :id', ['id' => $staleId]));
        self::assertSame(1, (int) $this->admin->fetchOne('SELECT COUNT(*) FROM admin_job_run WHERE job_key = \'test-job\' AND status = \'succeeded\''));
    }

    public function testUnknownKeyCannotExecuteAnArbitraryCommand(): void
    {
        $tester = $this->testerFor('test:job:success', Command::SUCCESS);

        self::assertSame(Command::INVALID, $tester->execute(['job' => 'cache:clear']));
        self::assertSame(0, (int) $this->admin->fetchOne('SELECT COUNT(*) FROM admin_job_run'));
    }

    public function testConcurrentRunOfTheSameJobIsRejectedWithoutCreatingHistory(): void
    {
        $lockConnection = DriverManager::getConnection($this->admin->getParams());
        try {
            self::assertTrue((bool) $lockConnection->fetchOne(
                'SELECT pg_try_advisory_lock(hashtext(\'clubscheduler.admin_job\'), hashtext(\'test-job\'))',
            ));
            $tester = $this->testerFor('test:job:success', Command::SUCCESS);

            self::assertSame(Command::FAILURE, $tester->execute(['job' => 'test-job']));
            self::assertSame(0, (int) $this->admin->fetchOne('SELECT COUNT(*) FROM admin_job_run'));
        } finally {
            $lockConnection->fetchOne('SELECT pg_advisory_unlock(hashtext(\'clubscheduler.admin_job\'), hashtext(\'test-job\'))');
            $lockConnection->close();
        }
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);
        $this->admin = $registry->getConnection('admin');
        $this->admin->executeStatement('DELETE FROM admin_job_run');
    }

    private function testerFor(string $targetName, int|RuntimeException $result): CommandTester
    {
        $definition = new AdminJobDefinition('test-job', 'Test job', $targetName);
        $catalog = new AdminJobCatalog([$definition]);
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);
        $runner = new AdminJobRunner(new AdminJobRunStore($registry));
        $wrapper = new RunAdminJobCommand($catalog, $runner);
        $target = new class($targetName, $result) extends Command {
            public function __construct(string $name, private readonly int|RuntimeException $result)
            {
                parent::__construct($name);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                if ($this->result instanceof RuntimeException) {
                    throw $this->result;
                }

                $output->writeln('potentially sensitive output');

                return $this->result;
            }
        };

        $application = new Application;
        $application->setAutoExit(false);
        $application->addCommand($target);
        $application->addCommand($wrapper);

        return new CommandTester($wrapper);
    }

    /** @return array<string, mixed> */
    private function singleRun(): array
    {
        $run = $this->admin->fetchAssociative('SELECT * FROM admin_job_run');
        self::assertIsArray($run);

        return $run;
    }
}
