<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A15/A16 — committed env hygiene. Production must be debug-off and ship NO secret
 * value; the dev/test secrets must be obvious placeholders; the base .env must
 * define the secrets the prod boot guard (App\Security\ProdSecretGuard, invoked
 * from Kernel::boot) compares against; and the once-committed secret must never
 * return in a TRACKED env file. Static assertions, like MercureHardeningTest.
 */
#[Group('phase1')]
final class EnvHygieneTest extends TestCase
{
    private const BACKEND = __DIR__ . '/../..';

    /** The concrete APP_SECRET that used to live in .env.dev/.env.test. */
    private const LEAKED_SECRET = '6be95ec77f384792f8abcaf31cf2f807';

    /**
     * Non-comment, non-blank `KEY=value` lines of a committed env file.
     *
     * @return list<string>
     */
    private static function assignments(string $file): array
    {
        $content = (string) file_get_contents(self::BACKEND . $file);

        return array_values(array_filter(
            array_map('trim', explode("\n", $content)),
            static fn (string $line): bool => '' !== $line && !str_starts_with($line, '#'),
        ));
    }

    /**
     * Committed (git-tracked) env files under backend/, so a newly-added tracked
     * file is covered and an untracked local override is not. Falls back to the
     * known set if git is unavailable.
     *
     * @return list<string> paths relative to backend/
     */
    private static function trackedEnvFiles(): array
    {
        $backend = realpath(self::BACKEND);
        if (false !== $backend) {
            $out = [];
            exec('git -C ' . escapeshellarg($backend) . ' ls-files -- ' . escapeshellarg('.env*') . ' 2>/dev/null', $out, $code);
            if (0 === $code && [] !== $out) {
                return array_map(static fn (string $p): string => '/' . $p, $out);
            }
        }

        return ['/.env', '/.env.dev', '/.env.test', '/.env.prod', '/.env.dist'];
    }

    public function testProdProfileOnlyTurnsDebugOffAndCommitsNoSecretValue(): void
    {
        $lines = self::assignments('/.env.prod');
        self::assertContains('APP_DEBUG=0', $lines, 'backend/.env.prod must set APP_DEBUG=0.');
        // No secret-bearing key may carry a value here (DATABASE_URL/CORS/MAILER
        // embed credentials too). Non-secret prod flags stay allowed.
        foreach ($lines as $line) {
            self::assertDoesNotMatchRegularExpression(
                '/^(APP_SECRET|JWT_PASSPHRASE|MERCURE_JWT_SECRET|POSTGRES_PASSWORD|DATABASE_URL|DATABASE_ADMIN_URL|CORS_ALLOW_ORIGIN|MAILER_DSN)=/',
                $line,
                \sprintf('backend/.env.prod must not commit a secret value; found: %s', $line),
            );
        }
    }

    public function testDevAndTestSecretsAreObviousPlaceholders(): void
    {
        foreach (['/.env.dev', '/.env.test'] as $file) {
            $content = (string) file_get_contents(self::BACKEND . $file);
            // Anchored to the active assignment line — a commented placeholder must not satisfy it.
            self::assertMatchesRegularExpression('/^APP_SECRET=\S*insecure\S*$/m', $content, \sprintf('backend%s APP_SECRET must be an obvious placeholder (contains \'insecure\').', $file));
        }
    }

    public function testBaseEnvDefinesTheGuardedSecrets(): void
    {
        // ProdSecretGuard reads these committed values from .env to detect a prod
        // boot that never overrode them; if the base .env stopped defining one, the
        // guard would have nothing to compare and silently stop protecting it.
        $base = (string) file_get_contents(self::BACKEND . '/.env');
        foreach (['APP_SECRET', 'JWT_PASSPHRASE', 'MERCURE_JWT_SECRET'] as $key) {
            self::assertMatchesRegularExpression('/^' . $key . '=\S+/m', $base, \sprintf('base .env must define %s (ProdSecretGuard compares the runtime value against it).', $key));
        }
    }

    public function testTheLeakedSecretNeverComesBackInATrackedFile(): void
    {
        $files = self::trackedEnvFiles();
        self::assertNotEmpty($files, 'expected to enumerate tracked backend env files.');
        foreach ($files as $file) {
            self::assertStringNotContainsString(self::LEAKED_SECRET, (string) file_get_contents(self::BACKEND . $file), \sprintf('The once-committed APP_SECRET must not reappear in backend%s.', $file));
        }
    }
}
