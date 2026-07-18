<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Closure;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * `%env(sentryDsn:SENTRY_DSN)%` — valide la FORME du DSN avant que le SDK Sentry ne
 * le parse. Un DSN malformé (typo au collage, valeur tronquée) lèverait dans les
 * listeners du bundle → toutes les requêtes en 500. Ici : DSN invalide → null
 * (SDK inactif) + log ERROR — l'observabilité ne fait JAMAIS tomber le backend
 * (revue #258, finding 2). Vide → null (état « câblé, pas activé »).
 */
final readonly class SentryDsnEnvVarProcessor implements EnvVarProcessorInterface
{
    /** Forme minimale d'un DSN Sentry : scheme://clé@hôte/id-projet. */
    private const DSN_PATTERN = '#^https?://[^@\s]+@[^/\s]+/\d+$#';

    public function __construct(private LoggerInterface $logger) {}

    public static function getProvidedTypes(): array
    {
        return ['sentryDsn' => 'string'];
    }

    public function getEnv(string $prefix, string $name, Closure $getEnv): ?string
    {
        $value = $getEnv($name);
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        if (1 !== preg_match(self::DSN_PATTERN, $value)) {
            $this->logger->error('Invalid Sentry DSN in {name} — Sentry stays DISABLED (backend keeps serving).', ['name' => $name]);

            return null;
        }

        return $value;
    }
}
