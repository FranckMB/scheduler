<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Team;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Exception\ImportRejectedException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Parses a REAL FBI club-wide fixtures export (.xlsx) — « Saisie des résultats
 * pour tout le club » — measured on specs/initiales/rechercherRencontre.xlsx
 * (cadrage P1-4 §3, facts F1-F9):
 *
 *   Division · N° de match · Equipe 1 (home) · Equipe 2 (away) ·
 *   Date de rencontre · Heure · Salle · e-Marque V2 · Scores/Forfaits (ignored).
 *
 * One-pass flow (décision fondateur 2026-08-02 — « une passe, pas 2 imports ») :
 * 1. analyze(): dry-run — parses, groups rows by division (+ club-team label when
 *    two club teams share one division), resolves each group against the
 *    persisted Division↔team mapping (= the Competition rows). Writes NOTHING.
 * 2. The manager completes the unresolved mappings in the dialog.
 * 3. import(): same file + the mappings — creates the missing Competitions and
 *    creates/updates every Fixture in one pass.
 *
 * Row semantics:
 * - HOME/AWAY: the club name (word-boundary normalized) must appear in exactly
 *   ONE of the two team labels; none or both (intra-club derby) → row error.
 * - « Exempt » as either team = a bye round → skipped, counted, never an error.
 * - Heure « 00:00 » = NOT SET (FBI sentinel, fact F2) → kickoffTime null; a
 *   real hour from the file always wins, but 00:00 never erases a kickoff the
 *   club has set (at home the CLUB proposes the hour).
 * - Salle → Fixture.fbiVenueLabel, HOME and AWAY (fact F3); absent on brassage
 *   rows (fact F4) → null.
 * - N° de match → Fixture.externalRef, scoped per team (fact F6: numbers repeat
 *   across divisions); known ref → DIFF/UPDATE, not skip:
 *     · date changed, or HOME↔AWAY switched → updated + un-placed
 *       (status UNPLACED, venue cleared) + warning — the league re-decided
 *       (« si le fichier reprogramme, c'est que c'est pas passé à la ligue »).
 *     · real hour changed → updated in place (venue kept: at home the hour is
 *       imposed, the venue stays the club's choice) + warning when it was placed.
 *     · salle/opponent label drift → silent update.
 * - Division → the persisted mapping; unmapped rows are neither created nor
 *   errors: they are reported per division for the mapping step.
 *
 * Per-row error report (never fail-fast past the header): valid rows import
 * even when others fail.
 */
final class FbiFixtureImporter
{
    /**
     * Header candidates per logical column, normalized. The real export titles
     * « N° de match » (trailing space included) where PR-4 assumed « Numéro » —
     * both are accepted (fact F8: labels are not under our control).
     */
    private const HEADER_CANDIDATES = [
        'division' => ['division'],
        'numero' => ['n de match', 'numero', 'no de match'],
        'equipe1' => ['equipe 1'],
        'equipe2' => ['equipe 2'],
        'date' => ['date de rencontre'],
        'heure' => ['heure'],
        'salle' => ['salle'],
    ];

    private const EXEMPT_LABEL = 'exempt';

    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /**
     * Dry-run: the division groups of the file, each resolved (or not) against
     * the persisted mapping. Writes nothing.
     *
     * @return array{
     *     divisions: list<array{name: string, fbiTeamLabel: string|null, rowCount: int, teamId: string|null, competitionId: string|null}>,
     *     totalRows: int,
     *     exempted: int,
     *     errors: list<string>,
     * }
     */
    public function analyze(string $filePath, Club $club): array
    {
        $parsed = $this->parseFile($filePath, $club);

        $groups = $this->groupRows($parsed['rows']);
        $resolver = $this->buildCompetitionResolver();

        $divisions = [];
        foreach ($groups as $group) {
            $competition = $resolver($group['divisionKey'], $group['labelKey'], $group['multiLabel']);
            $divisions[] = [
                'name' => $group['name'],
                'fbiTeamLabel' => $group['multiLabel'] ? $group['label'] : null,
                'rowCount' => $group['rowCount'],
                'teamId' => $competition?->getTeamId(),
                'competitionId' => $competition?->getId(),
            ];
        }

        return [
            'divisions' => $divisions,
            'totalRows' => \count($parsed['rows']) + $parsed['exempted'],
            'exempted' => $parsed['exempted'],
            'errors' => $parsed['errors'],
        ];
    }

    /**
     * One-pass import: persists the new mappings (missing Competitions), then
     * creates/updates every resolvable Fixture.
     *
     * @param list<array{division: string, fbiTeamLabel: string|null, teamId: string}> $mappings
     *
     * @return array{
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     exempted: int,
     *     errors: list<string>,
     *     warnings: list<array{type: string, division: string, externalRef: string, message: string}>,
     *     unmappedDivisions: list<array{name: string, fbiTeamLabel: string|null, rowCount: int}>,
     * }
     */
    public function import(string $filePath, Club $club, array $mappings): array
    {
        $parsed = $this->parseFile($filePath, $club);
        $errors = $parsed['errors'];

        $this->persistMappings($mappings, $club);
        $resolver = $this->buildCompetitionResolver();

        // Existing FBI refs of the whole club, keyed team|ref (fact F6: the
        // number is only unique within a division → within its team).
        /** @var array<string, Fixture> $existingByTeamRef */
        $existingByTeamRef = [];
        foreach ($this->entityManager->getRepository(Fixture::class)->findAll() as $existing) {
            if (null !== $existing->getExternalRef()) {
                $existingByTeamRef[$existing->getTeamId() . '|' . $existing->getExternalRef()] = $existing;
            }
        }

        $groups = $this->groupRows($parsed['rows']);
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $warnings = [];
        $unmapped = [];
        $dirty = false;
        /** @var array<string, true> $seenInFile intra-file duplicate guard, team|ref */
        $seenInFile = [];

        foreach ($groups as $group) {
            $competition = $resolver($group['divisionKey'], $group['labelKey'], $group['multiLabel']);
            if (!$competition instanceof Competition) {
                $unmapped[] = [
                    'name' => $group['name'],
                    'fbiTeamLabel' => $group['multiLabel'] ? $group['label'] : null,
                    'rowCount' => $group['rowCount'],
                ];
                continue;
            }
            $teamId = $competition->getTeamId();

            foreach ($group['rows'] as $row) {
                $key = $teamId . '|' . $row['numero'];
                if (isset($seenInFile[$key])) {
                    continue;
                }
                $seenInFile[$key] = true;

                $existing = $existingByTeamRef[$key] ?? null;
                if ($existing instanceof Fixture) {
                    $outcome = $this->applyDiff($existing, $row, $group['name'], $warnings);
                    if ('updated' === $outcome) {
                        ++$updated;
                        $dirty = true;
                    } else {
                        ++$unchanged;
                    }
                    continue;
                }

                $fixture = new Fixture;
                $fixture->setClubId($club->getId());
                $fixture->setSeasonId($competition->getSeasonId());
                $fixture->setTeamId($teamId);
                $fixture->setCompetitionId($competition->getId());
                $fixture->setMatchDate($row['matchDate']);
                $fixture->setHomeAway($row['homeAway']);
                $fixture->setOpponentLabel($row['opponentLabel']);
                $fixture->setKickoffTime($row['kickoffTime']);
                $fixture->setExternalRef($row['numero']);
                $fixture->setFbiVenueLabel($row['venueLabel']);
                $this->entityManager->persist($fixture);
                ++$created;
                $dirty = true;
            }
        }

        if ($dirty) {
            $this->entityManager->flush();
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'exempted' => $parsed['exempted'],
            'errors' => $errors,
            'warnings' => $warnings,
            'unmappedDivisions' => $unmapped,
        ];
    }

    /**
     * @param array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null} $row
     * @param list<array{type: string, division: string, externalRef: string, message: string}>                                                                                   $warnings
     */
    private function applyDiff(Fixture $existing, array $row, string $divisionName, array &$warnings): string
    {
        $changed = false;
        $wasPlaced = FixtureStatus::UNPLACED !== $existing->getStatus();

        if ($existing->getMatchDate()->format('Y-m-d') !== $row['matchDate']->format('Y-m-d')) {
            $oldDate = $existing->getMatchDate()->format('d/m/Y');
            $existing->setMatchDate($row['matchDate']);
            $this->unplace($existing);
            $warnings[] = [
                'type' => 'RESCHEDULED',
                'division' => $divisionName,
                'externalRef' => $row['numero'],
                'message' => \sprintf(
                    '%s n°%s : re-programmé du %s au %s%s.',
                    $divisionName,
                    $row['numero'],
                    $oldDate,
                    $row['matchDate']->format('d/m/Y'),
                    $wasPlaced ? ' — placement annulé' : '',
                ),
            ];
            $changed = true;
        }

        if ($existing->getHomeAway() !== $row['homeAway']) {
            $existing->setHomeAway($row['homeAway']);
            $this->unplace($existing);
            $warnings[] = [
                'type' => 'SWITCHED',
                'division' => $divisionName,
                'externalRef' => $row['numero'],
                'message' => FixtureHomeAway::AWAY === $row['homeAway']
                    ? \sprintf('%s n°%s : devient un match à l\'EXTÉRIEUR le %s%s.', $divisionName, $row['numero'], $row['matchDate']->format('d/m/Y'), $wasPlaced ? ' — le créneau placé est libéré' : '')
                    : \sprintf('%s n°%s : devient un match à DOMICILE le %s — à placer.', $divisionName, $row['numero'], $row['matchDate']->format('d/m/Y')),
            ];
            $changed = true;
        }

        // A real hour from the file always wins; the 00:00 sentinel (parsed to
        // null) never erases an hour the club has set (fact F2).
        if ($row['kickoffTime'] instanceof DateTimeImmutable) {
            $current = $existing->getKickoffTime();
            if (!$current instanceof DateTimeImmutable || $current->format('H:i') !== $row['kickoffTime']->format('H:i')) {
                $existing->setKickoffTime($row['kickoffTime']);
                if ($wasPlaced && FixtureStatus::UNPLACED !== $existing->getStatus()) {
                    $warnings[] = [
                        'type' => 'RESCHEDULED',
                        'division' => $divisionName,
                        'externalRef' => $row['numero'],
                        'message' => \sprintf('%s n°%s : la ligue enregistre %s comme heure de la rencontre.', $divisionName, $row['numero'], $row['kickoffTime']->format('H:i')),
                    ];
                }
                $changed = true;
            }
        }

        if ($existing->getOpponentLabel() !== $row['opponentLabel']) {
            $existing->setOpponentLabel($row['opponentLabel']);
            $changed = true;
        }
        if (null !== $row['venueLabel'] && $existing->getFbiVenueLabel() !== $row['venueLabel']) {
            $existing->setFbiVenueLabel($row['venueLabel']);
            $changed = true;
        }

        return $changed ? 'updated' : 'unchanged';
    }

    /** The league re-decided: the match goes back to « à placer ». */
    private function unplace(Fixture $fixture): void
    {
        $fixture->setStatus(FixtureStatus::UNPLACED);
        $fixture->setVenueId(null);
    }

    /**
     * Persists the manager's mapping choices as Competition rows (the durable
     * Division↔team correspondence, fact F7). Reuses an existing row for the
     * same (team, division); type inferred from the name (« Brassage »).
     *
     * @param list<array{division: string, fbiTeamLabel: string|null, teamId: string}> $mappings
     */
    private function persistMappings(array $mappings, Club $club): void
    {
        if ([] === $mappings) {
            return;
        }
        $teamRepository = $this->entityManager->getRepository(Team::class);
        $competitionRepository = $this->entityManager->getRepository(Competition::class);
        $dirty = false;

        foreach ($mappings as $mapping) {
            $name = mb_substr(trim($mapping['division']), 0, 180);
            if ('' === $name) {
                throw ImportRejectedException::badRequest('Correspondance invalide : division vide.');
            }
            // Tenant+season filters scope the lookup: a foreign teamId is
            // invisible → clean rejection, no cross-tenant write.
            $team = $teamRepository->find($mapping['teamId']);
            if (!$team instanceof Team) {
                throw ImportRejectedException::badRequest(\sprintf('Correspondance « %s » : équipe introuvable.', $name));
            }

            $label = null !== $mapping['fbiTeamLabel'] ? mb_substr(trim($mapping['fbiTeamLabel']), 0, 180) : null;
            $existing = $competitionRepository->findOneBy(['teamId' => $team->getId(), 'name' => $name]);
            if ($existing instanceof Competition) {
                // The manager's LATEST choice wins: only refreshing a null label
                // would leave a drifted FBI label permanently unresolvable — the
                // manager re-maps, the import still reports the division
                // unmapped, forever (label mismatch loop).
                if (null !== $label && $existing->getFbiTeamLabel() !== $label) {
                    $existing->setFbiTeamLabel($label);
                    $dirty = true;
                }
                continue;
            }

            $competition = new Competition;
            $competition->setClubId($club->getId());
            $competition->setSeasonId($team->getSeasonId());
            $competition->setTeamId($team->getId());
            $competition->setName($name);
            $competition->setCompetitionType(
                str_contains($this->normalizeLabel($name), 'brassage') ? CompetitionType::BRASSAGE : CompetitionType::CHAMPIONSHIP,
            );
            $competition->setFbiTeamLabel($label);
            $this->entityManager->persist($competition);
            $dirty = true;
        }

        if ($dirty) {
            $this->entityManager->flush();
        }
    }

    /**
     * The persisted Division↔team resolver. Rules:
     * - single club label in the division → any Competition whose normalized
     *   name matches (label ignored: the nominal case stores none);
     * - several club labels in the division (two club teams, fact F7bis) → the
     *   Competition whose fbiTeamLabel matches the row's club label; a
     *   label-less Competition never resolves a multi-label division (it cannot
     *   say WHICH team it is — the manager re-maps once, per label).
     *
     * @return callable(string, string, bool): (Competition|null)
     */
    private function buildCompetitionResolver(): callable
    {
        /** @var array<string, list<Competition>> $byName tenant+season filters scope findAll() */
        $byName = [];
        foreach ($this->entityManager->getRepository(Competition::class)->findAll() as $competition) {
            $byName[$this->normalizeLabel($competition->getName())][] = $competition;
        }

        return function (string $divisionKey, string $labelKey, bool $multiLabel) use ($byName): ?Competition {
            $candidates = $byName[$divisionKey] ?? [];
            if ([] === $candidates) {
                return null;
            }
            if (!$multiLabel) {
                return $candidates[0];
            }
            foreach ($candidates as $candidate) {
                $candidateLabel = $candidate->getFbiTeamLabel();
                if (null !== $candidateLabel && $this->normalizeLabel($candidateLabel) === $labelKey) {
                    return $candidate;
                }
            }

            return null;
        };
    }

    /**
     * Groups parsed rows by division, splitting per club-team label when the
     * division carries several distinct ones (two club teams in one division).
     *
     * @param list<array{divisionName: string, divisionKey: string, clubLabel: string, clubLabelKey: string, numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}> $rows
     *
     * @return list<array{name: string, divisionKey: string, label: string, labelKey: string, multiLabel: bool, rowCount: int, rows: list<array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}>}>
     */
    private function groupRows(array $rows): array
    {
        /** @var array<string, array<string, array{name: string, label: string, rows: list<array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}>}>> $tree division → labelKey → bucket */
        $tree = [];
        foreach ($rows as $row) {
            $bucket = $tree[$row['divisionKey']][$row['clubLabelKey']] ?? ['name' => $row['divisionName'], 'label' => $row['clubLabel'], 'rows' => []];
            $bucket['rows'][] = [
                'numero' => $row['numero'],
                'matchDate' => $row['matchDate'],
                'homeAway' => $row['homeAway'],
                'opponentLabel' => $row['opponentLabel'],
                'kickoffTime' => $row['kickoffTime'],
                'venueLabel' => $row['venueLabel'],
            ];
            $tree[$row['divisionKey']][$row['clubLabelKey']] = $bucket;
        }

        $groups = [];
        foreach ($tree as $divisionKey => $byLabel) {
            $multiLabel = \count($byLabel) > 1;
            foreach ($byLabel as $labelKey => $bucket) {
                $groups[] = [
                    'name' => $bucket['name'],
                    'divisionKey' => $divisionKey,
                    'label' => $bucket['label'],
                    'labelKey' => $labelKey,
                    'multiLabel' => $multiLabel,
                    'rowCount' => \count($bucket['rows']),
                    'rows' => $bucket['rows'],
                ];
            }
        }

        return $groups;
    }

    /**
     * Parses the file into normalized rows + per-row errors + the exempt count.
     * Shared by analyze() and import() so both see the exact same rows.
     *
     * @return array{
     *     rows: list<array{divisionName: string, divisionKey: string, clubLabel: string, clubLabelKey: string, numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}>,
     *     exempted: int,
     *     errors: list<string>,
     * }
     */
    private function parseFile(string $filePath, Club $club): array
    {
        // Reader pinned to Xlsx (no auto-detection): the upload check only
        // gates on name/mime, so an arbitrary payload must never reach the
        // Html/Csv/Xml readers (defense-in-depth, security-review PR-4).
        $spreadsheet = IOFactory::load($filePath, 0, [IOFactory::READER_XLSX]);
        $sheetRows = $spreadsheet->getActiveSheet()->toArray();

        if ([] === $sheetRows || $this->isEmptyRow($sheetRows[0] ?? [])) {
            return ['rows' => [], 'exempted' => 0, 'errors' => ['Excel file is empty.']];
        }

        $header = array_shift($sheetRows);
        $columnMap = $this->buildColumnMap($header);
        $columns = [];
        foreach (self::HEADER_CANDIDATES as $field => $candidates) {
            foreach ($candidates as $candidate) {
                if (isset($columnMap[$candidate])) {
                    $columns[$field] = $columnMap[$candidate];
                    break;
                }
            }
        }
        foreach (['division', 'numero', 'equipe1', 'equipe2', 'date'] as $required) {
            if (!isset($columns[$required])) {
                throw ImportRejectedException::badRequest('Colonnes requises manquantes : Division, N° de match, Equipe 1, Equipe 2, Date de rencontre.');
            }
        }

        $clubNeedle = $this->normalizeLabel($club->getName());
        $rows = [];
        $errors = [];
        $exempted = 0;

        foreach ($sheetRows as $rowIndex => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }
            $line = $rowIndex + 2; // header consumed, xlsx rows are 1-based

            $divisionName = mb_substr($this->stringValue($row[$columns['division']] ?? null), 0, 180);
            $numero = $this->stringValue($row[$columns['numero']] ?? null);
            $equipe1 = $this->stringValue($row[$columns['equipe1']] ?? null);
            $equipe2 = $this->stringValue($row[$columns['equipe2']] ?? null);
            $rawDate = $row[$columns['date']] ?? null;
            $rawTime = isset($columns['heure']) ? ($row[$columns['heure']] ?? null) : null;
            $venueLabel = isset($columns['salle']) ? $this->stringValue($row[$columns['salle']] ?? null) : '';

            if (\in_array('', [$divisionName, $numero, $equipe1, $equipe2], true)) {
                $errors[] = \sprintf('Ligne %d : Division, N° de match, Equipe 1 et Equipe 2 sont requis.', $line);
                continue;
            }

            // A bye round (« Exempt » on either side) is not a match (fact F5).
            if (self::EXEMPT_LABEL === $this->normalizeLabel($equipe1) || self::EXEMPT_LABEL === $this->normalizeLabel($equipe2)) {
                ++$exempted;
                continue;
            }

            // Column is VARCHAR(64): an over-length number must be a row error,
            // not a DBAL exception aborting the whole import (security-review PR-4).
            if (mb_strlen($numero) > 64) {
                $errors[] = \sprintf('Ligne %d : numéro de rencontre trop long (max 64 caractères).', $line);
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
            if (!$matchDate instanceof DateTimeImmutable) {
                $errors[] = \sprintf('Ligne %d : date de rencontre invalide (attendu jj/mm/aaaa).', $line);
                continue;
            }

            $kickoffTime = null;
            if (null !== $rawTime && '' !== $this->stringValue($rawTime)) {
                $kickoffTime = $this->parseTime($rawTime);
                if (!$kickoffTime instanceof DateTimeImmutable) {
                    $errors[] = \sprintf('Ligne %d : heure invalide (attendu HH:MM).', $line);
                    continue;
                }
                // « 00:00 » is the FBI sentinel for « not set yet » (fact F2:
                // 45/124 rows of the real export) — storing midnight would
                // fabricate placements. No real match kicks off at midnight.
                if ('00:00' === $kickoffTime->format('H:i')) {
                    $kickoffTime = null;
                }
            }

            $clubLabel = $matchesHome ? $equipe1 : $equipe2;
            $rows[] = [
                'divisionName' => $divisionName,
                'divisionKey' => $this->normalizeLabel($divisionName),
                'clubLabel' => mb_substr($clubLabel, 0, 180),
                'clubLabelKey' => $this->normalizeLabel($clubLabel),
                'numero' => $numero,
                'matchDate' => $matchDate,
                'homeAway' => $matchesHome ? FixtureHomeAway::HOME : FixtureHomeAway::AWAY,
                // Column is VARCHAR(180) — clamp instead of failing the row on
                // an absurdly long label.
                'opponentLabel' => mb_substr($matchesHome ? $equipe2 : $equipe1, 0, 180),
                'kickoffTime' => $kickoffTime,
                'venueLabel' => '' === $venueLabel ? null : mb_substr($venueLabel, 0, 180),
            ];
        }

        return ['rows' => $rows, 'exempted' => $exempted, 'errors' => $errors];
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
