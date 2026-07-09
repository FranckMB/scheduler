<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\ProdSecretGuard;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A16: the prod boot guard must reject a committed dev secret on any guarded key
 * (crypto secrets AND DB DSNs), and never false-trip on a legitimate value.
 */
#[Group('phase1')]
final class ProdSecretGuardTest extends TestCase
{
    public function testRejectsTheCommittedDevAppSecret(): void
    {
        $vars = ['APP_SECRET' => 'change-me-in-dev'] + $this->realSecrets();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_SECRET.*committed development secret/');
        ProdSecretGuard::assert($vars);
    }

    public function testRejectsADevDbPasswordEmbeddedInTheDsn(): void
    {
        $vars = $this->realSecrets();
        $vars['DATABASE_ADMIN_URL'] = 'postgresql://clubscheduler:clubscheduler_dev_password@db:5432/prod';
        $this->expectException(RuntimeException::class);
        ProdSecretGuard::assert($vars);
    }

    public function testRejectsTheDevMercureSecret(): void
    {
        $vars = $this->realSecrets();
        $vars['MERCURE_JWT_SECRET'] = 'clubscheduler_dev_mercure_hs256_secret_change_me';
        $this->expectException(RuntimeException::class);
        ProdSecretGuard::assert($vars);
    }

    public function testPassesWithFullyOverriddenRealSecrets(): void
    {
        ProdSecretGuard::assert($this->realSecrets());
        $this->addToAssertionCount(1);
    }

    public function testDoesNotFalseTripOnALegitimateSecretThatMerelyLooksSecure(): void
    {
        // "secure" is a substring of the dev marker "insecure" — a value-based
        // marker match would wrongly reject this; exact committed-string match does not.
        $vars = $this->realSecrets();
        $vars['APP_SECRET'] = 'a-perfectly-secure-random-value';
        ProdSecretGuard::assert($vars);
        $this->addToAssertionCount(1);
    }

    /** @return array<string, string> */
    private function realSecrets(): array
    {
        return [
            'APP_SECRET' => 'Zx9RealRandomAppSecret',
            'JWT_PASSPHRASE' => 'Qw8RealPassphrase',
            'MERCURE_JWT_SECRET' => 'Rt7RealMercureSecret',
            'DATABASE_URL' => 'postgresql://app:Str0ngProdPass@db:5432/prod',
            'DATABASE_ADMIN_URL' => 'postgresql://admin:An0therProdPass@db:5432/prod',
        ];
    }
}
