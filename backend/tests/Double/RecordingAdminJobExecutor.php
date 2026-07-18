<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\AdminJob\AdminJobAlreadyRunning;
use App\AdminJob\AdminJobDefinition;
use App\AdminJob\AdminJobExecutorInterface;
use Symfony\Component\Console\Command\Command;

/** Network-free executor used by HTTP security tests in the test environment. */
final class RecordingAdminJobExecutor implements AdminJobExecutorInterface
{
    /** @var list<array{key: string, superAdminId: string, arguments: array<string, bool|int|string>}> */
    public array $calls = [];

    public ?string $alreadyRunningForKey = null;

    /** @var array<string, int> */
    public array $exitCodes = [];

    public function run(AdminJobDefinition $definition, string $superAdminId): int
    {
        // SA4 : les arguments (dont le --club des actions) font partie du contrat observé.
        $this->calls[] = ['key' => $definition->key, 'superAdminId' => $superAdminId, 'arguments' => $definition->arguments];
        if ($definition->key === $this->alreadyRunningForKey) {
            throw new AdminJobAlreadyRunning('already running');
        }

        return $this->exitCodes[$definition->key] ?? Command::SUCCESS;
    }

    public function reset(): void
    {
        $this->calls = [];
        $this->alreadyRunningForKey = null;
        $this->exitCodes = [];
    }
}
