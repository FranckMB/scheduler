<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\FfbbApiClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Lot C SSRF guard (A12): FfbbApiClient only ever talks to the two hard-coded
 * FFBB hosts and validates the club code format before any use downstream.
 */
#[Group('unit')]
final class FfbbApiClientTest extends TestCase
{
    public function testClubCodeFormatIsValidated(): void
    {
        self::assertTrue(FfbbApiClient::isValidClubCode('ARA0069036'));
        self::assertTrue(FfbbApiClient::isValidClubCode('GES1234567'));
        self::assertFalse(FfbbApiClient::isValidClubCode('not-a-code'));
        self::assertFalse(FfbbApiClient::isValidClubCode('../etc/passwd'));
        self::assertFalse(FfbbApiClient::isValidClubCode('ARA069036'));   // 6 digits
        self::assertFalse(FfbbApiClient::isValidClubCode(''));
    }

    public function testOnlyTalksToFixedFfbbHosts(): void
    {
        $hosts = [];
        $client = new FfbbApiClient(new MockHttpClient(function (string $method, string $url) use (&$hosts): MockResponse {
            $hosts[] = parse_url($url, \PHP_URL_HOST);
            if (str_contains($url, 'configuration')) {
                return new MockResponse((string) json_encode(['data' => ['key_ms' => 't']]));
            }

            return new MockResponse((string) json_encode(['results' => [['hits' => []]]]));
        }));

        $client->search('ARA0069036');

        self::assertNotEmpty($hosts);
        foreach ($hosts as $host) {
            self::assertContains($host, ['api.ffbb.com', 'meilisearch-prod.ffbb.app'], 'no host outside the FFBB allowlist');
        }
    }
}
