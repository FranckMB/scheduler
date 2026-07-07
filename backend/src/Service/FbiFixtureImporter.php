<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Team;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Parses an FBI per-team fixtures export (.xlsx) into Fixture rows (spec
 * gestion-matchs §5: one export per team, same format every time → one parser
 * called per team; the target team is CHOSEN at upload, never guessed).
 *
 * ⚠ ASSUMED FORMAT (no real FBI export was available — validate against a real
 * file before hardening; see specs/courantes/module-matchs.md):
 *   Division · Numéro · Équipe 1 (home) · Équipe 2 (away) · Date de rencontre ·
 *   Heure (optional) · Salle (read but NOT stored — away venues are palier B).
 *
 * Row semantics:
 * - HOME/AWAY: the club name (normalized) must appear in exactly ONE of the two
 *   team labels; none or both (intra-club derby) → per-row error, never a guess.
 * - Numéro → Fixture.externalRef, the idempotence key: re-uploading the same
 *   file creates nothing (skip; re-programmed dates are NOT updated in PR-4).
 * - Division → the team's Competition (find-or-create, CHAMPIONSHIP).
 * - Status is always UNPLACED: placing requires a CLUB venue + an explicit
 *   manager action (the placement panel); an FBI Heure only pre-fills
 *   kickoffTime (a proposal at home, radar food away).
 *
 * Per-row error report (never fail-fast past the header), same semantics as
 * FfbbExcelImporter: valid rows import even when others fail.
 */
final class FbiFixtureImporter
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /**
     * The controller has already authorized the caller and loaded the
     * tenant-scoped Team + Club — this only parses and persists.
     *
     * @return array{created: int, skipped: int, errors: list<string>}
     */
    public function import(string $filePath, Team $team, Club $club): array
    {
        // Reader pinned to Xlsx (no auto-detection): the upload check only
        // gates on name/mime, so an arbitrary payload must never reach the
        // Html/Csv/Xml readers (defense-in-depth, security-review PR-4).
        $spreadsheet = IOFactory::load($filePath, 0, [IOFactory::READER_XLSX]);
        $rows = $spreadsheet->getActiveSheet()->toArray();

        if ([] === $rows || $this->isEmptyRow($rows[0] ?? [])) {
            return ['created' => 0, 'skipped' => 0, 'errors' => ['Excel file is empty.']];
        }

        $header = array_shift($rows);
        $columnMap = $this->buildColumnMap($header);
        foreach (['division', 'numero', 'equipe 1', 'equipe 2', 'date de rencontre'] as $required) {
            if (!isset($columnMap[$required])) {
                throw new InvalidArgumentException('Required columns missing: Division, Numéro, Équipe 1, Équipe 2, Date de rencontre.');
            }
        }

        $clubNeedle = $this->normalizeLabel($club->getName());

        // Existing FBI numbers of THIS team (tenant filters scope the query).
        $existingRefs = [];
        foreach ($this->entityManager->getRepository(Fixture::class)->findBy(['teamId' => $team->getId()]) as $existing) {
            if (null !== $existing->getExternalRef()) {
                $existingRefs[$existing->getExternalRef()] = true;
            }
        }

        /** @var array<string, Competition> $competitionsByName in-file cache (avoids intra-file duplicates before flush) */
        $competitionsByName = [];

        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $rowIndex => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }
            $line = $rowIndex + 2; // header consumed, xlsx rows are 1-based

            // Clamped to the competition.name column (VARCHAR(180)) BEFORE the
            // lookup so find-or-create and the in-file cache share one key.
            $division = mb_substr($this->stringValue($row[$columnMap['division']] ?? null), 0, 180);
            $numero = $this->stringValue($row[$columnMap['numero']] ?? null);
            $equipe1 = $this->stringValue($row[$columnMap['equipe 1']] ?? null);
            $equipe2 = $this->stringValue($row[$columnMap['equipe 2']] ?? null);
            $rawDate = $row[$columnMap['date de rencontre']] ?? null;
            $rawTime = isset($columnMap['heure']) ? ($row[$columnMap['heure']] ?? null) : null;

            if ('' === $division || '' === $numero || '' === $equipe1 || '' === $equipe2) {
                $errors[] = \sprintf('Ligne %d : Division, Numéro, Équipe 1 et Équipe 2 sont requis.', $line);
                continue;
            }

            // Column is VARCHAR(64): an over-length number must be a row error,
            // not a DBAL exception aborting the whole import (security-review PR-4).
            if (mb_strlen($numero) > 64) {
                $errors[] = \sprintf('Ligne %d : numéro de rencontre trop long (max 64 caractères).', $line);
                continue;
            }

            if (isset($existingRefs[$numero])) {
                ++$skipped;
                continue;
            }

            // Word-boundary containment (space-padded), NOT raw substring:
            // club "BC Test" must not match opponent "BC Testville".
            $matchesHome = $this->containsClub($equipe1, $clubNeedle);
            $matchesAway = $this->containsClub($equipe2, $clubNeedle);
            if ($matchesHome === $matchesAway) {
                $errors[] = $matchesHome
                    ? \sprintf('Ligne %d : les deux équipes correspondent au club « %s » (derby intra-club) — saisissez ce match manuellement.', $line, $club->getName())
                    : \sprintf('Ligne %d : aucune équipe ne correspond au club « %s » — vérifiez le nom du club.', $line, $club->getName());
                continue;
            }

            $matchDate = $this->parseDate($rawDate);
            if (null === $matchDate) {
                $errors[] = \sprintf('Ligne %d : date de rencontre invalide (attendu jj/mm/aaaa).', $line);
                continue;
            }

            $kickoffTime = null;
            if (null !== $rawTime && '' !== $this->stringValue($rawTime)) {
                $kickoffTime = $this->parseTime($rawTime);
                if (null === $kickoffTime) {
                    $errors[] = \sprintf('Ligne %d : heure invalide (attendu HH:MM).', $line);
                    continue;
                }
            }

            $competition = $competitionsByName[$division] ?? $this->findOrCreateCompetition($division, $team);
            $competitionsByName[$division] = $competition;

            $fixture = new Fixture;
            $fixture->setClubId($team->getClubId());
            $fixture->setSeasonId($team->getSeasonId());
            $fixture->setTeamId($team->getId());
            $fixture->setCompetitionId($competition->getId());
            $fixture->setMatchDate($matchDate);
            $fixture->setHomeAway($matchesHome ? FixtureHomeAway::HOME : FixtureHomeAway::AWAY);
            // Column is VARCHAR(180) — clamp instead of failing the row on an
            // absurdly long label.
            $fixture->setOpponentLabel(mb_substr($matchesHome ? $equipe2 : $equipe1, 0, 180));
            $fixture->setKickoffTime($kickoffTime);
            $fixture->setExternalRef($numero);
            $this->entityManager->persist($fixture);

            $existingRefs[$numero] = true; // intra-file duplicate → skipped
            ++$created;
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function findOrCreateCompetition(string $name, Team $team): Competition
    {
        $existing = $this->entityManager->getRepository(Competition::class)->findOneBy([
            'teamId' => $team->getId(),
            'name' => $name,
        ]);
        if ($existing instanceof Competition) {
            return $existing;
        }

        $competition = new Competition;
        $competition->setClubId($team->getClubId());
        $competition->setSeasonId($team->getSeasonId());
        $competition->setTeamId($team->getId());
        $competition->setName($name);
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $this->entityManager->persist($competition);

        return $competition;
    }

    /**
     * "03/10/2026" or "3/10/2026" (single digits tolerated — an Excel d/m/yyyy
     * cell format renders unpadded), optionally followed by a time, or an Excel
     * serial → the match date. Calendar-invalid dates (31/02) are rejected
     * instead of silently rolling over.
     */
    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (is_numeric($value) && !\is_string($value)) {
            return DateTimeImmutable::createFromMutable(ExcelDate::excelToDateTimeObject((float) $value))->setTime(0, 0);
        }

        $text = $this->stringValue($value);
        if (1 !== preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $text, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!j/n/Y', \sprintf('%d/%d/%d', (int) $m[1], (int) $m[2], (int) $m[3]));

        return false === $date ? null : $date;
    }

    /** "15:30" / "9:30" or an Excel day-fraction → the kickoff time. */
    private function parseTime(mixed $value): ?DateTimeImmutable
    {
        if (is_numeric($value) && !\is_string($value)) {
            $minutes = (int) round(((float) $value) * 24 * 60);

            return new DateTimeImmutable('1970-01-01')->setTime(intdiv($minutes, 60) % 24, $minutes % 60);
        }

        $text = $this->stringValue($value);
        if (1 === preg_match('/^(\d{1,2}):([0-5]\d)/', $text, $m) && (int) $m[1] <= 23) {
            return new DateTimeImmutable('1970-01-01')->setTime((int) $m[1], (int) $m[2]);
        }

        return null;
    }

    /**
     * Whole-word containment of the club needle in a team label: both sides are
     * normalized and space-padded so "bc test" matches "bc test 1" but never
     * "bc testville" (word boundary, not substring).
     */
    private function containsClub(string $label, string $clubNeedle): bool
    {
        return str_contains(' ' . $this->normalizeLabel($label) . ' ', ' ' . $clubNeedle . ' ');
    }

    /**
     * Header labels AND team labels tolerate case/accents/spacing drift: FBI
     * exports are not under our control.
     */
    private function normalizeLabel(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $lower = mb_strtolower(false === $ascii ? $value : $ascii, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $lower)));
    }

    /**
     * @param array<mixed> $header
     *
     * @return array<string, int>
     */
    private function buildColumnMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $value) {
            $key = $this->normalizeLabel($this->stringValue($value));
            if ('' !== $key) {
                $map[$key] = $index;
            }
        }

        return $map;
    }

    private function stringValue(mixed $value): string
    {
        if (null === $value) {
            return '';
        }
        if (\is_string($value)) {
            return trim($value);
        }
        if (is_numeric($value)) {
            return (string) $value;
        }

        return '';
    }

    /** @param array<mixed> $row */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ('' !== $this->stringValue($value)) {
                return false;
            }
        }

        return true;
    }
}
