<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SEC-05/06 non-regression: the Mercure hub must stay hardened. Static guard on
 * the tracked docker-compose.yml — a live subscribe-without-JWT check would need
 * the hub running, but the regression we must catch is a config edit re-adding
 * `anonymous` or the wildcard CORS, which this reads directly.
 */
#[Group('phase1')]
#[Group('integration')]
final class MercureHardeningTest extends TestCase
{
    private string $compose;

    public function testNoAnonymousSubscribers(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/^\s*anonymous\s*$/m',
            $this->compose,
            'Mercure hub must NOT allow anonymous subscribers (SEC-05).',
        );
    }

    public function testCorsOriginsNotWildcard(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/cors_origins\s+\*/',
            $this->compose,
            'Mercure cors_origins must be an explicit allow-list, not "*" (SEC-05).',
        );
    }

    public function testNoWildcardPublishOrigins(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/publish_origins\s+\*/',
            $this->compose,
            'Mercure publish_origins "*" must not be re-introduced (SEC-05).',
        );
    }

    public function testHubUsesDedicatedSecretNotJwtPassphrase(): void
    {
        // The hub keys must reference the dedicated MERCURE_JWT_SECRET, never the
        // lexik JWT_PASSPHRASE (SEC-06 — the two were the same value before).
        self::assertMatchesRegularExpression(
            '/MERCURE_(PUBLISHER|SUBSCRIBER)_JWT_KEY:\s*\$\{MERCURE_JWT_SECRET\}/',
            $this->compose,
            'Mercure hub must sign with ${MERCURE_JWT_SECRET} (SEC-06).',
        );
        self::assertDoesNotMatchRegularExpression(
            '/MERCURE_(PUBLISHER|SUBSCRIBER)_JWT_KEY:\s*\$\{JWT_PASSPHRASE\}/',
            $this->compose,
            'Mercure hub must not reuse ${JWT_PASSPHRASE} (SEC-06 collision).',
        );
    }

    protected function setUp(): void
    {
        // Repo root is mounted at /app (./:/app); tests run in /app/backend.
        $path = \dirname(__DIR__, 3) . '/docker-compose.yml';
        $contents = is_file($path) ? file_get_contents($path) : false;
        self::assertIsString($contents, "docker-compose.yml not found at {$path}");
        $this->compose = $contents;
    }
}
