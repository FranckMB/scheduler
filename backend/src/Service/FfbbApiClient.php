<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * SSRF-safe client for the FFBB public API (lot C). TWO fixed hosts only:
 *  - api.ffbb.com (Directus) → public Meilisearch token from /items/configuration
 *  - meilisearch-prod.ffbb.app → organisme search (index ffbbserver_organismes)
 *
 * See backend/docs/ffbb-api.md. Hardening: hosts are hard-coded (never derived
 * from input); the club code is format-validated (isValidClubCode) before any
 * call; redirects are disabled (max_redirects=0) so a compromised endpoint
 * cannot bounce us to an internal address; a tight timeout bounds each call.
 * The public token is cached in-memory and refetched once on a 401 (rotation).
 */
final class FfbbApiClient
{
    private const CONFIG_URL = 'https://api.ffbb.com/items/configuration';
    private const SEARCH_URL = 'https://meilisearch-prod.ffbb.app/multi-search';
    private const ORIGIN = 'https://competitions.ffbb.com';
    private const INDEX = 'ffbbserver_organismes';
    private const TIMEOUT = 8.0;

    /** FFBB club code: league prefix (2-4 letters) + 7 digits, e.g. ARA0069036. */
    private const CLUB_CODE_RE = '/^[A-Z]{2,4}\d{7}$/';

    private ?string $token = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $ffbbMeilisearchToken = '',
    ) {
        // Optional prod override; else the public token is fetched at runtime.
        if ('' !== $this->ffbbMeilisearchToken) {
            $this->token = $this->ffbbMeilisearchToken;
        }
    }

    public static function isValidClubCode(string $code): bool
    {
        return 1 === preg_match(self::CLUB_CODE_RE, $code);
    }

    /**
     * Search organismes by free text (club code, or committee/league name to
     * resolve a parent). Returns the raw hit list (possibly empty). Transport
     * failures propagate — callers (FfbbClubPopulator) treat them best-effort.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 3): array
    {
        $response = $this->postSearch($query, $limit);
        if (401 === $response->getStatusCode()) {
            // Token rotated (or the env override is stale): drop it and refetch
            // the public token from the config endpoint, then retry once.
            $this->token = null;
            $response = $this->postSearch($query, $limit);
        }

        $data = $response->toArray(false);
        $hits = $data['results'][0]['hits'] ?? null;

        return \is_array($hits) ? array_values(array_filter($hits, 'is_array')) : [];
    }

    private function postSearch(string $query, int $limit): ResponseInterface
    {
        return $this->httpClient->request('POST', self::SEARCH_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token(),
                'Content-Type' => 'application/json',
            ],
            'json' => ['queries' => [['indexUid' => self::INDEX, 'q' => $query, 'limit' => $limit]]],
            'timeout' => self::TIMEOUT,
            'max_duration' => self::TIMEOUT,
            'max_redirects' => 0,
        ]);
    }

    private function token(): string
    {
        if (null !== $this->token && '' !== $this->token) {
            return $this->token;
        }

        $data = $this->httpClient->request('GET', self::CONFIG_URL, [
            'headers' => [
                'Origin' => self::ORIGIN,
                'Referer' => self::ORIGIN . '/',
                'Accept' => 'application/json',
            ],
            'timeout' => self::TIMEOUT,
            'max_duration' => self::TIMEOUT,
            'max_redirects' => 0,
        ])->toArray(false);

        $key = $data['data']['key_ms'] ?? null;
        if (!\is_string($key) || '' === $key) {
            throw new RuntimeException('FFBB config endpoint did not return a Meilisearch token.');
        }

        return $this->token = $key;
    }
}
