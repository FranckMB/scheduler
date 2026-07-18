<?php

declare(strict_types=1);

namespace App\Tests\Unit\DependencyInjection;

use App\DependencyInjection\SentryDsnEnvVarProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Le garde-fou « l'observabilité ne tombe jamais le backend » : un DSN malformé
 * devient null (SDK inactif) au lieu de lever dans les listeners du bundle
 * (500 toutes requêtes). Un DSN VALIDE passe intact — self-hosted à sous-chemin
 * compris (revue #258 round 2 : le rejeter désactivait Sentry en silence).
 */
final class SentryDsnEnvVarProcessorTest extends TestCase
{
    private SentryDsnEnvVarProcessor $processor;

    public function testEmptyAndMissingBecomeNull(): void
    {
        self::assertNull($this->resolve(''));
        self::assertNull($this->resolve(null));
    }

    public function testValidDsnsPassThroughIntact(): void
    {
        self::assertSame('https://abc123@o4505.ingest.sentry.io/4505123', $this->resolve('https://abc123@o4505.ingest.sentry.io/4505123'));
        // Self-hosted derrière un sous-chemin.
        self::assertSame('https://key@sentry.interne.fr/sentry/42', $this->resolve('https://key@sentry.interne.fr/sentry/42'));
        self::assertSame('http://key@localhost/1', $this->resolve('http://key@localhost/1'));
    }

    public function testMalformedDsnsDegradeToNullNeverThrow(): void
    {
        self::assertNull($this->resolve('not-a-dsn'));
        self::assertNull($this->resolve('https://missing-at-sign.example/42'));
        self::assertNull($this->resolve('https://key@host/'));
        self::assertNull($this->resolve('https://key@host/notdigits'));
        self::assertNull($this->resolve('https://key@ho st/42'));
    }

    protected function setUp(): void
    {
        $this->processor = new SentryDsnEnvVarProcessor(new NullLogger);
    }

    private function resolve(?string $value): ?string
    {
        return $this->processor->getEnv('sentryDsn', 'SENTRY_DSN', static fn (): ?string => $value);
    }
}
