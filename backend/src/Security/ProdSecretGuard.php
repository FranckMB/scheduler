<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

/**
 * A16 fail-closed. The committed base `.env` ships FUNCTIONAL development secrets
 * so the stack runs out of the box; Symfony's Dotenv order
 * (`.env → .env.$env → .env.$env.local`) means a prod boot that forgets its
 * untracked `.env.prod.local` silently falls back to those publicly-committed
 * values. This guard is invoked from Kernel::boot() — so it fires for EVERY
 * entrypoint (HTTP, console, messenger-worker), not just web requests — and
 * refuses to boot prod while any guarded secret still contains a KNOWN committed
 * dev value. Matching the exact committed strings (not generic markers like
 * "insecure") means a legitimate random production secret can never false-trip it.
 */
final class ProdSecretGuard
{
    /** Env keys whose value must never be a committed dev secret in prod (DSNs embed the DB password). */
    private const GUARDED_KEYS = ['APP_SECRET', 'JWT_PASSPHRASE', 'MERCURE_JWT_SECRET', 'DATABASE_URL', 'DATABASE_ADMIN_URL'];

    /** The exact secret values committed to the tracked env files (base .env + .env.dev/.test). */
    private const COMMITTED_DEV_SECRETS = [
        'change-me-in-dev',
        'clubscheduler_dev_jwt_passphrase',
        'clubscheduler_dev_mercure_hs256_secret_change_me',
        'clubscheduler_dev_password',
        'app_user_password',
        'dev-insecure-not-a-real-secret-do-not-use-in-prod',
    ];

    /**
     * @param array<string, mixed> $vars resolved environment (e.g. `$_SERVER + $_ENV`)
     *
     * @throws RuntimeException if a guarded key still holds a committed dev secret
     */
    public static function assert(array $vars): void
    {
        foreach (self::GUARDED_KEYS as $key) {
            $value = (string) ($vars[$key] ?? '');
            if ('' === $value) {
                continue;
            }
            foreach (self::COMMITTED_DEV_SECRETS as $devSecret) {
                if (str_contains($value, $devSecret)) {
                    throw new RuntimeException(\sprintf('%s still holds a committed development secret in the prod environment. Override it in an untracked backend/.env.prod.local (or the runtime environment) before deploying.', $key));
                }
            }
        }
    }
}
