<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A15/A16 — committed env hygiene. Production must be debug-off and ship NO secret
 * value; the dev/test secrets must be obvious placeholders; the base .env's
 * functional dev secrets must stay recognizable so the prod boot guard
 * (ProdSecretGuardListener) rejects them; and the once-committed secret must never
 * return in a TRACKED env file. Static file assertions, like MercureHardeningTest.
 */
#[Group('phase1')]
final class EnvHygieneTest extends TestCase
{
    private const BACKEND = __DIR__ . '/../..';

    /** The concrete APP_SECRET that used to live in .env.dev/.env.test. */
    private const LEAKED_SECRET = '6be95ec77f384792f8abcaf31cf2f807';

    /** Substrings that mark a value as a dev/test placeholder (mirror of ProdSecretGuardListener). */
    private const DEV_MARKERS = '/change-me|change_me|clubscheduler_dev|insecure/';

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

    public function testProdProfileOnlyTurnsDebugOffAndCommitsNoSecretValue(): void
    {
        $lines = self::assignments('/.env.prod');
        self::assertNotEmpty($lines, 'backend/.env.prod must exist and set APP_DEBUG=0.');
        // Every active line must be exactly APP_DEBUG=0 — any other KEY=value would
        // be a committed secret (DATABASE_URL/CORS/MAILER embed credentials too).
        foreach ($lines as $line) {
            self::assertSame('APP_DEBUG=0', $line, \sprintf('backend/.env.prod must only set APP_DEBUG=0 (no committed secrets); found: %s', $line));
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

    public function testBaseEnvSecretsStayRecognizableDevDefaults(): void
    {
        // The base .env ships functional dev secrets; they MUST carry a dev marker
        // so ProdSecretGuardListener rejects them if they leak into a prod boot.
        $base = (string) file_get_contents(self::BACKEND . '/.env');
        foreach (['APP_SECRET', 'JWT_PASSPHRASE', 'MERCURE_JWT_SECRET'] as $key) {
            self::assertSame(1, preg_match('/^' . $key . '=(\S+)/m', $base, $m), \sprintf('base .env must define %s.', $key));
            self::assertMatchesRegularExpression(self::DEV_MARKERS, $m[1] ?? '', \sprintf('base .env %s must stay a recognizable dev default (the prod guard rejects these markers).', $key));
        }
    }

    public function testTheLeakedSecretNeverComesBackInATrackedFile(): void
    {
        // Only TRACKED committed files — a developer's git-ignored *.local is theirs.
        foreach (glob(self::BACKEND . '/.env*') ?: [] as $file) {
            if (str_ends_with($file, '.local')) {
                continue;
            }
            self::assertStringNotContainsString(self::LEAKED_SECRET, (string) file_get_contents($file), \sprintf('The once-committed APP_SECRET must not reappear in %s.', $file));
        }
    }
}
