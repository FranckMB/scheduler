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
    private const PLACE_MATCHES_URL = 'http://engine:8000/place-matches';
    private const VALIDATE_URL = 'http://engine:8000/validate-assignments';

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

    /**
     * P1-4 PR D — the dated match-placement solve (contract 2.2, ADR-0003).
     * Same propagation contract as solve(): exceptions bubble up unchanged.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function placeMatches(array $payload, int $timeoutSeconds): array
    {
        $response = $this->httpClient->request('POST', self::PLACE_MATCHES_URL, [
            'json' => $payload,
            'timeout' => $timeoutSeconds,
        ]);

        return $response->toArray(false);
    }

    /**
     * P2-2 F2a — le verdict moteur sur UN candidat de déplacement (contrat 2.4).
     * Mono-candidat, appel court (baseline figée côté engine). Même propagation
     * que solve() : les exceptions remontent inchangées, l'appelant décide.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function validateAssignment(array $payload, int $timeoutSeconds): array
    {
        $response = $this->httpClient->request('POST', self::VALIDATE_URL, [
            'json' => $payload,
            'timeout' => $timeoutSeconds,
        ]);

        return $response->toArray(false);
    }
}
