<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

/**
 * A16 fail-closed. The committed base `.env` ships FUNCTIONAL development secrets
 * so the stack runs out of the box; Symfony's Dotenv order
 * (`.env → .env.$env → .env.$env.local`) means a prod boot that forgets its
 * untracked `.env.prod.local` silently falls back to those publicly-committed
 * values. Invoked from Kernel::boot() — so it fires for EVERY entrypoint (HTTP,
 * console, messenger-worker) — this refuses to boot prod while any guarded secret
 * still EQUALS the value committed in `.env`.
 *
 * Reading the committed values from `.env` (rather than a hand-maintained
 * denylist) keeps enforcement in lockstep with what is actually committed: a
 * rotated or newly-added committed dev secret is covered automatically. Exact
 * equality (not substring) means a legitimate random prod secret can never
 * false-trip the guard.
 */
final class ProdSecretGuard
{
    /** Env keys whose value must never equal the committed dev one in prod (DSNs embed the DB password). */
    private const GUARDED_KEYS = ['APP_SECRET', 'JWT_PASSPHRASE', 'MERCURE_JWT_SECRET', 'DATABASE_URL', 'DATABASE_ADMIN_URL'];

    /**
     * @param array<string, mixed> $runtimeVars resolved environment (e.g. `$_SERVER + $_ENV`)
     *
     * @throws RuntimeException if, in prod, a guarded key still equals the committed `.env` value
     */
    public static function assertForEnvironment(string $environment, array $runtimeVars, string $baseEnvPath): void
    {
        if ('prod' !== $environment) {
            return;
        }

        $committed = self::parseEnvFile($baseEnvPath);

        foreach (self::GUARDED_KEYS as $key) {
            $runtime = self::readValue($runtimeVars, $key);
            $committedValue = $committed[$key] ?? null;

            if (null !== $committedValue && '' !== $runtime && $runtime === $committedValue) {
                throw new RuntimeException(\sprintf('%s still equals the value committed in backend/.env — override it in an untracked backend/.env.prod.local (or the runtime environment) before deploying to prod.', $key));
            }
        }
    }

    /**
     * Runtime value from the passed env array, falling back to getenv() (Symfony resolves %env()% from it too).
     *
     * @param array<string, mixed> $vars
     */
    private static function readValue(array $vars, string $key): string
    {
        $value = $vars[$key] ?? getenv($key);

        return \is_string($value) ? $value : '';
    }

    /**
     * Minimal `.env` parse: `KEY=value` lines, quotes stripped, comments/blank skipped.
     *
     * @return array<string, string>
     */
    private static function parseEnvFile(string $path): array
    {
        $lines = @file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            return [];
        }

        $vars = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $vars[trim($key)] = trim($value, " \t\"'");
        }

        return $vars;
    }
}
