<?php

declare(strict_types=1);

namespace App\AdminJob;

/** Executes one definition that has already been selected from the closed catalog. */
interface AdminJobExecutorInterface
{
    public function run(AdminJobDefinition $definition, string $superAdminId): int;
}
