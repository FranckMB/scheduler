<?php

declare(strict_types=1);

namespace App\Tests\Double;

use DateTimeImmutable;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Deterministic FFBB backend for the TEST env (wired in services_test.yaml as
 * FfbbApiClient's HTTP client): integration tests must never hit the real
 * federation. Serves the config token, one engagement for the club code
 * ARA0000001 (« Pré test masculine », Poule T of 4 clubs → 6 expected
 * matchdays) and the matching competition detail. Any other query → empty hits.
 */
final class FfbbHttpClientStub implements HttpClientInterface
{
    public const CLUB_CODE = 'ARA0000001';
    public const COMPETITION_ID = '900000000000001';
    public const COMPETITION_CODE = 'PTM';
    public const POULE_ID = '910000000000001';
    public const POULE_CLUBS = ['AS TEST NORD', 'BC TEST SUD', 'ES TEST OUEST', 'US TEST EST'];

    private readonly MockHttpClient $inner;

    public function __construct()
    {
        $this->inner = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_contains($url, 'api.ffbb.com')) {
                return new MockResponse((string) json_encode(['data' => ['key_ms' => 'stub-token']]));
            }
            $body = \is_string($options['body'] ?? null) ? $options['body'] : '';

            if (str_contains($body, 'ffbbserver_engagements')) {
                $hits = str_contains($body, self::CLUB_CODE) ? [[
                    'codeClub' => self::CLUB_CODE,
                    'sexe' => 'Masculin',
                    'categorie' => ['code' => 'SE', 'libelle' => 'Seniors'],
                    'niveau' => ['code' => 'DEP', 'libelle' => 'Départemental'],
                    'idCompetition' => ['id' => self::COMPETITION_ID, 'code' => self::COMPETITION_CODE, 'nom' => 'Pré test masculine'],
                    'idPoule' => ['id' => self::POULE_ID, 'nom' => 'Poule T'],
                ]] : [];

                return $this->search($hits);
            }
            if (str_contains($body, 'ffbbserver_salles') && str_contains($body, '_geoRadius')) {
                // P2-21 lot D : la recherche GÉO — 2 salles dans un petit rayon,
                // une 3e apparaît en élargissant (teste l'auto-élargissement).
                $small = str_contains($body, '3000)');
                $hits = [
                    ['libelle' => 'GYMNASE PROCHE', 'adresse' => '1 rue Près', 'numero' => '900000001',
                        'cartographie' => ['ville' => 'Testville', 'latitude' => 45.001, 'longitude' => 4.001]],
                    ['libelle' => 'SALLE VOISINE', 'adresse' => '2 rue À-Côté', 'numero' => '900000002',
                        'cartographie' => ['ville' => 'Testville', 'latitude' => 45.002, 'longitude' => 4.002]],
                ];
                if (!$small) {
                    $hits[] = ['libelle' => 'GYMNASE LOINTAIN', 'adresse' => '9 route Loin', 'numero' => '900000009',
                        'cartographie' => ['ville' => 'Ailleurs', 'latitude' => 45.05, 'longitude' => 4.05]];
                }

                return $this->search($hits);
            }
            if (str_contains($body, 'ffbbserver_salles')) {
                // P2-20 : deux salles pour le CP de test, une SANS libellé (le
                // mapping serveur doit l'écarter au lieu de rendre une ligne vide).
                // ⚠ Ne pas matcher les apostrophes du filtre : l'encodeur JSON de
                // Symfony les sérialise en ' — on matche le CP seul.
                $hits = str_contains($body, '69100') ? [
                    ['libelle' => 'SALLE ZOLA', 'adresse' => '251 cours Émile Zola', 'numero' => '166900001',
                        'cartographie' => ['ville' => 'Villeurbanne', 'latitude' => 45.76672, 'longitude' => 4.9076]],
                    ['libelle' => 'GYMNASE MATEO', 'adresse' => '5 BIS RUE EMILE DUNIERE', 'numero' => '166926604',
                        'cartographie' => ['ville' => 'Villeurbanne', 'latitude' => 45.78017, 'longitude' => 4.88467]],
                    ['libelle' => '', 'adresse' => 'sans nom'],
                ] : [];

                return $this->search($hits);
            }
            if (str_contains($body, 'ffbbserver_competitions')) {
                $hits = str_contains($body, self::COMPETITION_CODE) ? [[
                    'id' => self::COMPETITION_ID,
                    'code' => self::COMPETITION_CODE,
                    'nom' => 'Pré test masculine',
                    'saison' => ['code' => $this->currentSeasonCode()],
                    'poules' => [[
                        'id' => self::POULE_ID,
                        'nom' => 'Poule T',
                        'engagements' => array_map(static fn (string $nom): array => ['nom' => $nom], self::POULE_CLUBS),
                    ]],
                ]] : [];

                return $this->search($hits);
            }

            return $this->search([]);
        });
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

    /** « 26-27 » for today's season — the reader filters on the CURRENT season. */
    private function currentSeasonCode(): string
    {
        $now = new DateTimeImmutable('today');
        $year = (int) $now->format('Y') - ((int) $now->format('md') < 715 ? 1 : 0);

        return \sprintf('%02d-%02d', $year % 100, ($year + 1) % 100);
    }

    /** @param list<array<string, mixed>> $hits */
    private function search(array $hits): MockResponse
    {
        return new MockResponse((string) json_encode(['results' => [['hits' => $hits]]]));
    }
}
