<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\ProdSecretGuard;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A16: the prod boot guard must reject a runtime secret that still equals the
 * value committed in .env (crypto secrets AND DB DSNs), on every entrypoint via
 * the environment gate, and never false-trip a genuinely overridden value.
 */
#[Group('phase1')]
final class ProdSecretGuardTest extends TestCase
{
    private string $envFile;

    public function testProdRejectsANonOverriddenCommittedSecret(): void
    {
        $vars = ['APP_SECRET' => 'change-me-in-dev'] + $this->overridden();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_SECRET.*committed in backend\/\.env/');
        ProdSecretGuard::assertForEnvironment('prod', $vars, $this->envFile);
    }

    public function testProdRejectsANonOverriddenDbDsn(): void
    {
        $vars = $this->overridden();
        $vars['DATABASE_ADMIN_URL'] = 'postgresql://admin:committed_admin_pw@postgres:5432/db';
        $this->expectException(RuntimeException::class);
        ProdSecretGuard::assertForEnvironment('prod', $vars, $this->envFile);
    }

    public function testProdPassesWhenEverySecretIsOverridden(): void
    {
        ProdSecretGuard::assertForEnvironment('prod', $this->overridden(), $this->envFile);
        $this->addToAssertionCount(1);
    }

    public function testNonProdEnvironmentIsNeverGuarded(): void
    {
        // dev/test legitimately run on the committed values.
        ProdSecretGuard::assertForEnvironment('dev', ['APP_SECRET' => 'change-me-in-dev'] + $this->overridden(), $this->envFile);
        $this->addToAssertionCount(1);
    }

    public function testFallsBackToGetenvForTheRuntimeValue(): void
    {
        putenv('APP_SECRET=change-me-in-dev');
        $vars = $this->overridden();
        unset($vars['APP_SECRET']); // absent from the array → must be read via getenv()
        try {
            $this->expectException(RuntimeException::class);
            ProdSecretGuard::assertForEnvironment('prod', $vars, $this->envFile);
        } finally {
            putenv('APP_SECRET');
        }
    }

    protected function setUp(): void
    {
        $this->envFile = (string) tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($this->envFile, <<<'ENV'
            # committed dev defaults
            APP_SECRET=change-me-in-dev
            JWT_PASSPHRASE=committed_dev_passphrase
            MERCURE_JWT_SECRET=committed_dev_mercure
            DATABASE_URL="postgresql://app_user:committed_dev_pw@postgres:5432/db"
            DATABASE_ADMIN_URL="postgresql://admin:committed_admin_pw@postgres:5432/db"
            ENV);
    }

    protected function tearDown(): void
    {
        @unlink($this->envFile);
    }

    /** @return array<string, string> — every guarded key overridden to a real prod value */
    private function overridden(): array
    {
        return [
            'APP_SECRET' => 'Zx9RealRandomAppSecret',
            'JWT_PASSPHRASE' => 'Qw8RealPassphrase',
            'MERCURE_JWT_SECRET' => 'Rt7RealMercureSecret',
            'DATABASE_URL' => 'postgresql://app_user:Str0ngProdPass@db:5432/prod',
            'DATABASE_ADMIN_URL' => 'postgresql://admin:An0therProdPass@db:5432/prod',
        ];
    }
}
