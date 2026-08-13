<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * P5-3b — Cloudflare siteverify déterministe pour le TEST env (câblé dans
 * services_test.yaml comme client HTTP de TurnstileVerifier) : aucun test
 * d'inscription ne doit joindre Cloudflare.
 *
 * Le verdict est PILOTABLE et l'URL réellement appelée est ENREGISTRÉE. Les deux
 * sont STATIQUES parce que le client est un service partagé lu à travers la
 * frontière de requête (le kernel reboote entre deux requêtes browser-kit) — même
 * patron que RecordingPasswordHasher.
 */
final class TurnstileHttpClientStub implements HttpClientInterface
{
    /** 'success' | 'failure' | 'transport' | 'garbage' — le verdict que le stub rendra. */
    public static string $verdict = 'success';

    /** La dernière URL POSTée à siteverify (assert : l'URL est bien celle EN DUR). */
    public static ?string $lastUrl = null;

    private readonly MockHttpClient $inner;

    public function __construct()
    {
        $this->inner = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::$lastUrl = $url;

            if ('transport' === self::$verdict) {
                // Panne réseau simulée : l'info `error` fait jeter une
                // TransportException à la lecture → verify() tombe en fail-open.
                return new MockResponse('', ['error' => 'simulated transport failure']);
            }

            if ('garbage' === self::$verdict) {
                // Cloudflare JOIGNABLE mais réponse illisible (page HTML d'edge) :
                // doit tomber en fail-CLOSED, pas emprunter le fail-open des pannes.
                return new MockResponse('<html>edge error</html>');
            }

            return new MockResponse((string) json_encode(['success' => 'success' === self::$verdict]));
        });
    }

    public static function reset(): void
    {
        self::$verdict = 'success';
        self::$lastUrl = null;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->inner->request($method, $url, $options);
    }

    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}
