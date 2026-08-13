<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Holds the correlation id (X-Request-Id) of the CURRENT request or handled
 * message, so the logs (RequestContextProcessor), the outbound engine call
 * (EngineClient) and the Sentry scope all stamp the SAME id.
 *
 * Mutable + one-writer-per-boundary, patron TenantConnectionContext :
 * RequestIdListener le pose par requête HTTP ; RequestIdMiddleware le pose et
 * le NETTOIE (try/finally) autour de chaque message — jamais de fuite d'un
 * message à l'autre sur le worker de longue vie.
 */
final class RequestIdContext
{
    private ?string $requestId = null;

    public function set(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function get(): ?string
    {
        return $this->requestId;
    }

    public function clear(): void
    {
        $this->requestId = null;
    }
}
