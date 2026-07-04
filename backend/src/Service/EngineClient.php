<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client for the solver engine's POST /generate (BCK-04: extracted from
 * GenerateScheduleHandler). Exceptions propagate unchanged — the handler owns
 * the failure→status mapping (a TransportExceptionInterface = timeout, any other
 * throw = engine error).
 */
final class EngineClient
{
    private const ENGINE_URL = 'http://engine:8000/generate';

    public function __construct(private readonly HttpClientInterface $httpClient) {}

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function solve(array $payload, int $timeoutSeconds): array
    {
        $response = $this->httpClient->request('POST', self::ENGINE_URL, [
            'json' => $payload,
            'timeout' => $timeoutSeconds,
        ]);

        return $response->toArray(false);
    }
}
