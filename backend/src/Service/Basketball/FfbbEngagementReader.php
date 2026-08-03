<?php

declare(strict_types=1);

namespace App\Service\Basketball;

/**
 * Reads the club's FFBB engagements for ONE season and joins each to its
 * competition's poule (P1-4 PR F). On-demand only — no cache, no cron (closed
 * legal decision: per-tenant consumption, never a global directory).
 *
 * Join (measured 2026-08-03): engagements are fetched by club code (strict
 * server-side codeClub filter), competitions by (code, saison.code) — `id` is
 * not filterable — then discriminated by the engagement's idCompetition.id.
 * The poule row gives the EXACT opponent club list (the import guard's data)
 * and its size (expectedMatchdays = 2×(N−1)).
 *
 * Double-encoded UTF-8 labels (« PrÃ© rÃ©gionale ») are repaired here, at the
 * boundary — never in display components.
 */
final class FfbbEngagementReader
{
    public function __construct(private readonly FfbbApiClient $apiClient) {}

    /** Expected matchdays per team for a poule of N clubs: home-and-away round robin. */
    public static function expectedMatchdays(int $pouleSize): ?int
    {
        return $pouleSize >= 2 ? 2 * ($pouleSize - 1) : null;
    }

    /**
     * @return list<array{
     *     ffbbCompetitionId: string,
     *     ffbbCompetitionCode: string,
     *     competitionName: string,
     *     ffbbPouleId: string,
     *     pouleName: string,
     *     category: string|null,
     *     level: string|null,
     *     gender: string|null,
     *     pouleSize: int,
     *     pouleOpponents: list<string>
     * }>
     */
    public function read(string $clubCode, int $seasonYear): array
    {
        $seasonCode = FfbbSeasonCode::fromSeasonYear($seasonYear);
        $competitionCache = [];
        $rows = [];

        foreach ($this->apiClient->searchEngagements($clubCode) as $hit) {
            $competition = \is_array($hit['idCompetition'] ?? null) ? $hit['idCompetition'] : null;
            $poule = \is_array($hit['idPoule'] ?? null) ? $hit['idPoule'] : null;
            $competitionId = $this->stringOrNull($competition['id'] ?? null);
            $competitionCode = $this->stringOrNull($competition['code'] ?? null);
            $pouleId = $this->stringOrNull($poule['id'] ?? null);
            if (\in_array(null, [$competition, $competitionId, $competitionCode, $pouleId], true)) {
                continue; // an engagement without competition/poule cannot be paired
            }

            $detail = $competitionCache[$competitionCode] ??= $this->apiClient->searchCompetitionsByCode($competitionCode, $seasonCode);
            $competitionRow = null;
            foreach ($detail as $candidate) {
                if ($this->stringOrNull($candidate['id'] ?? null) === $competitionId) {
                    $competitionRow = $candidate;
                    break;
                }
            }
            if (null === $competitionRow) {
                continue; // not this season (or not published) — never shown half-joined
            }

            $pouleRow = null;
            foreach (\is_array($competitionRow['poules'] ?? null) ? $competitionRow['poules'] : [] as $candidate) {
                if (\is_array($candidate) && $this->stringOrNull($candidate['id'] ?? null) === $pouleId) {
                    $pouleRow = $candidate;
                    break;
                }
            }
            if (null === $pouleRow) {
                continue;
            }

            $clubNames = [];
            foreach (\is_array($pouleRow['engagements'] ?? null) ? $pouleRow['engagements'] : [] as $engaged) {
                $name = \is_array($engaged) ? $this->stringOrNull($engaged['nom'] ?? null) : null;
                if (null !== $name) {
                    $clubNames[] = $this->fixEncoding($name);
                }
            }

            $rows[] = [
                'ffbbCompetitionId' => $competitionId,
                'ffbbCompetitionCode' => $competitionCode,
                'competitionName' => $this->fixEncoding($this->stringOrNull($competition['nom'] ?? null) ?? $competitionCode),
                'ffbbPouleId' => $pouleId,
                'pouleName' => $this->fixEncoding($this->stringOrNull($poule['nom'] ?? null) ?? ''),
                'category' => $this->labelOf($hit['categorie'] ?? null),
                'level' => $this->labelOf($hit['niveau'] ?? null),
                'gender' => $this->stringOrNull($hit['sexe'] ?? null),
                'pouleSize' => \count($clubNames),
                'pouleOpponents' => $clubNames,
            ];
        }

        return $rows;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (\is_int($value)) {
            return (string) $value;
        }

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /** @param mixed $value a FFBB {code, libelle} object */
    private function labelOf(mixed $value): ?string
    {
        if (!\is_array($value)) {
            return null;
        }
        $label = $this->stringOrNull($value['libelle'] ?? null) ?? $this->stringOrNull($value['code'] ?? null);

        return null === $label ? null : $this->fixEncoding($label);
    }

    /** Repair double-encoded UTF-8 (« PrÃ© rÃ©gionale » → « Pré régionale »), measured on the real index. */
    private function fixEncoding(string $value): string
    {
        // « Ã » (U+00C3) followed by a C1/Latin-1 punctuation-range codepoint is
        // the signature of UTF-8 read as Latin-1 then re-encoded.
        if (1 !== preg_match('/\x{00C3}[\x{0080}-\x{00BF}]/u', $value)) {
            return $value;
        }
        $decoded = mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');

        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : $value;
    }
}
