<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Basketball;

use App\Service\Basketball\FfbbApiClient;
use App\Service\Basketball\FfbbEngagementReader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The engagement → competition → poule join (P1-4 PR F), on canned Meilisearch
 * payloads shaped like the 2026-08-03 probes: strict codeClub filter (the field
 * is not filterable server-side), competition discriminated by id among the
 * code+season hits, poule opponents extracted, double-encoded labels repaired.
 */
#[Group('unit')]
final class FfbbEngagementReaderTest extends TestCase
{
    public function testJoinsEngagementToItsPouleAndReadsTheOpponents(): void
    {
        $reader = $this->reader();
        $rows = $reader->read('ARA0069036', 2026);

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('Pré régionale masculine', $row['competitionName']); // double encoding repaired
        self::assertSame('Poule B2', $row['pouleName']);
        self::assertSame('comp-1', $row['ffbbCompetitionId']);
        self::assertSame('poule-b2', $row['ffbbPouleId']);
        self::assertSame(4, $row['pouleSize']);
        self::assertSame(['AS COLLONGES', 'EVEIL SPORTIF JONAGEOIS', 'CS MENIVAL', 'B CHARPENNES'], $row['pouleOpponents']);
        self::assertSame(6, FfbbEngagementReader::expectedMatchdays($row['pouleSize'])); // 2×(4−1)
    }

    public function testForeignClubHitsAreFilteredOutStrictly(): void
    {
        // The full-text search rains 283 hits; only codeClub === ours survive.
        self::assertSame([], $this->reader()->read('ARA9999999', 2026));
    }

    public function testAnEngagementWhoseCompetitionIsNotThisSeasonIsDropped(): void
    {
        // saison.code filter: 2025 asks for « 25-26 », the canned competition is « 26-27 ».
        self::assertSame([], $this->reader()->read('ARA0069036', 2025));
    }

    public function testExpectedMatchdaysNeedsAtLeastTwoClubs(): void
    {
        self::assertNull(FfbbEngagementReader::expectedMatchdays(1));
        self::assertSame(22, FfbbEngagementReader::expectedMatchdays(12));
    }

    private function reader(): FfbbEngagementReader
    {
        $engagements = ['results' => [['hits' => [
            [
                // Double-encoded label, as measured on the real index.
                'codeClub' => 'ARA0069036',
                'idCompetition' => ['id' => 'comp-1', 'code' => 'PRM', 'nom' => "Pr\u{c3}\u{a9} r\u{c3}\u{a9}gionale masculine"],
                'idPoule' => ['id' => 'poule-b2', 'nom' => 'Poule B2'],
                'categorie' => ['code' => 'SE', 'libelle' => 'Seniors'],
                'niveau' => ['code' => 'REG', 'libelle' => 'Régional'],
                'sexe' => 'Masculin',
            ],
            [
                'codeClub' => 'GES0000001', // foreign hit rained by full-text search
                'idCompetition' => ['id' => 'comp-x', 'code' => 'PRM', 'nom' => 'Pré régionale masculine'],
                'idPoule' => ['id' => 'poule-x', 'nom' => 'Poule X'],
            ],
        ]]]];
        $competitions = ['results' => [['hits' => [
            [
                // Same code, other id (another league) — must be skipped.
                'id' => 'comp-other-league', 'code' => 'PRM', 'nom' => 'Pré régionale masculine',
                'saison' => ['code' => '26-27'], 'poules' => [],
            ],
            [
                'id' => 'comp-1', 'code' => 'PRM', 'nom' => 'Pré régionale masculine',
                'saison' => ['code' => '26-27'],
                'poules' => [
                    ['id' => 'poule-other', 'nom' => 'Poule A', 'engagements' => [['nom' => 'AILLEURS BC']]],
                    ['id' => 'poule-b2', 'nom' => 'Poule B2', 'engagements' => [
                        ['nom' => 'AS COLLONGES'], ['nom' => 'EVEIL SPORTIF JONAGEOIS'], ['nom' => 'CS MENIVAL'], ['nom' => 'B CHARPENNES'],
                    ]],
                ],
            ],
        ]]]];

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($engagements, $competitions): MockResponse {
            if (str_contains($url, 'api.ffbb.com')) {
                return new MockResponse((string) json_encode(['data' => ['key_ms' => 'token']]));
            }
            $body = \is_string($options['body'] ?? null) ? $options['body'] : '';
            // « 25-26 » asked → no competition of that season (empty hits).
            // (Symfony's json option encodes the apostrophes — match the code only.)
            if (str_contains($body, 'ffbbserver_competitions')) {
                return new MockResponse((string) json_encode(str_contains($body, '25-26') ? ['results' => [['hits' => []]]] : $competitions));
            }

            return new MockResponse((string) json_encode($engagements));
        });

        return new FfbbEngagementReader(new FfbbApiClient($httpClient));
    }
}
