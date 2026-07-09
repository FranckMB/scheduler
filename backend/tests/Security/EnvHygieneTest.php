<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A15/A16 — committed env hygiene. Production must be debug-off by default, the
 * dev/test APP_SECRETs must be obvious placeholders (never a real-secret-shaped
 * value a maintainer might paste into prod), and the specific secret that was
 * once committed must never come back. Static file assertions, like
 * MercureHardeningTest.
 */
#[Group('phase1')]
final class EnvHygieneTest extends TestCase
{
    private const BACKEND = __DIR__ . '/../..';

    /** The concrete APP_SECRET that used to live in .env.dev/.env.test. */
    private const LEAKED_SECRET = '6be95ec77f384792f8abcaf31cf2f807';

    public function testProdProfileTurnsDebugOff(): void
    {
        $prod = file_get_contents(self::BACKEND . '/.env.prod');
        self::assertIsString($prod, 'backend/.env.prod must exist (committed prod defaults).');
        self::assertMatchesRegularExpression('/^APP_DEBUG=0$/m', $prod, 'Production must default to APP_DEBUG=0.');
    }

    public function testProdProfileCommitsNoSecrets(): void
    {
        $prod = file_get_contents(self::BACKEND . '/.env.prod');
        self::assertIsString($prod);
        // A secret assignment has a non-empty, non-comment value on the same line.
        self::assertDoesNotMatchRegularExpression('/^(APP_SECRET|JWT_PASSPHRASE|MERCURE_JWT_SECRET|POSTGRES_PASSWORD)=\S/m', $prod, 'backend/.env.prod must not commit secret VALUES (only document them).');
    }

    public function testDevAndTestSecretsAreObviousPlaceholders(): void
    {
        foreach (['/.env.dev', '/.env.test'] as $file) {
            $content = file_get_contents(self::BACKEND . $file);
            self::assertIsString($content);
            self::assertMatchesRegularExpression('/APP_SECRET=\S*insecure\S*/', $content, "backend{$file} APP_SECRET must be an obvious placeholder (contains 'insecure').");
        }
    }

    public function testTheLeakedSecretNeverComesBack(): void
    {
        foreach (glob(self::BACKEND . '/.env*') ?: [] as $file) {
            self::assertStringNotContainsString(self::LEAKED_SECRET, (string) file_get_contents($file), "The once-committed APP_SECRET must not reappear in {$file}.");
        }
    }
}
