<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Schedule;
use App\Export\ScheduleExportData;
use App\Export\ScheduleExportDataProvider;
use DateInterval;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Flat, Excel-idiomatic export of a schedule: one row per training slot
 * (Jour, Début, Fin, Gymnase, Équipe, Catégorie, Coach), optionally scoped to a
 * single venue. Kept as a data table (not a visual grid) so a manager can sort
 * and filter it in Excel.
 */
class SpreadsheetGenerator
{
    /**
     * Les colonnes du tableau — foyer unique (audit D-18, 2026-08-09).
     *
     * ⚑ L'ordre des en-têtes était tenu ici, et celui des VALEURS par deux tuples positionnels
     * (placements, fenêtres vides) plus une plage `'A1:G1'` écrite en dur. Quatre listes à
     * garder d'accord, dont trois par la seule position.
     *
     * ⚠ **La divergence produit un fichier parfaitement valide.** Insérer une colonne ici
     * décale les valeurs d'un cran sans rien casser : Excel s'ouvre, les colonnes ont leurs
     * titres, et le gestionnaire lit des heures sous « Gymnase ». Aucune erreur, aucun test
     * rouge — un export faux qui se donne pour bon.
     *
     * Les lignes sont désormais construites PAR NOM et projetées ici : ajouter une colonne
     * rend une cellule vide (visible), plus jamais un décalage (invisible).
     */
    private const HEADERS = ['Jour', 'Début', 'Fin', 'Gymnase', 'Groupe', 'Équipe', 'Catégorie', 'Coach'];

    public function __construct(
        private readonly ScheduleExportDataProvider $exportData,
    ) {}

    /** @return string binary .xlsx content */
    public function generate(Schedule $schedule, ?string $venueId = null): string
    {
        $data = $this->exportData->load($schedule, $venueId);
        $venueName = static fn (string $id): string => $data->venues[$id]['name'] ?? '';

        // One sortable row per placement AND per empty window (team = "(vide)"), so
        // defined-but-unfilled windows appear in the table like the on-screen grid.
        /** @var list<array{day:int, start:string, venue:string, cells:array<string, string>}> $rows */
        $rows = [];
        foreach ($data->slots as $slot) {
            $start = $slot->getStartTime();
            $end = $start->add(new DateInterval('PT' . $slot->getDurationMinutes() . 'M'));
            $rows[] = [
                'day' => $slot->getDayOfWeek(), 'start' => $start->format('H:i'), 'venue' => $venueName($slot->getVenueId()),
                'cells' => [
                    'Jour' => ScheduleExportData::DAY_LABELS[$slot->getDayOfWeek()] ?? '',
                    'Début' => $start->format('H:i'),
                    'Fin' => $end->format('H:i'),
                    'Gymnase' => $venueName($slot->getVenueId()),
                    // Libellé de groupe (« CEC3 ») du créneau, vide s'il n'en porte pas. Projeté PAR
                    // NOM comme le reste : une colonne qu'aucune ligne ne renseigne sort vide.
                    'Groupe' => $data->groupLabels[$slot->getVenueId() . '|' . $slot->getDayOfWeek() . '|' . $start->format('H:i')] ?? '',
                    'Équipe' => $data->teamNames[$slot->getTeamId()] ?? '',
                    'Catégorie' => $data->teamCategories[$slot->getTeamId()] ?? '',
                    'Coach' => null !== $slot->getCoachId() ? ($data->coachNames[$slot->getCoachId()] ?? '') : '',
                ],
            ];
        }
        foreach ($data->emptySlots as $window) {
            $end = $window->startTime->add(new DateInterval('PT' . $window->durationMinutes . 'M'));
            $rows[] = [
                'day' => $window->dayOfWeek, 'start' => $window->startTime->format('H:i'), 'venue' => $venueName($window->venueId),
                'cells' => [
                    'Jour' => ScheduleExportData::DAY_LABELS[$window->dayOfWeek] ?? '',
                    'Début' => $window->startTime->format('H:i'),
                    'Fin' => $end->format('H:i'),
                    'Gymnase' => $venueName($window->venueId),
                    'Équipe' => '(vide)',
                ],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['day'], $a['start'], $a['venue']] <=> [$b['day'], $b['start'], $b['venue']]);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Planning');

        foreach (self::HEADERS as $i => $label) {
            $sheet->setCellValue([$i + 1, 1], $label);
        }
        $headerStyle = $sheet->getStyle('A1:' . Coordinate::stringFromColumnIndex(\count(self::HEADERS)) . '1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8E8E8');

        $row = 2;
        foreach ($rows as $r) {
            // Projection PAR NOM : une colonne qu'aucune ligne ne renseigne sort vide, jamais
            // décalée. Une clé qui ne serait plus une colonne disparaîtrait en silence — c'est
            // ce que `SpreadsheetColumnsAreProjectedByNameTest` interdit.
            foreach (self::HEADERS as $i => $column) {
                $sheet->setCellValue([$i + 1, $row], $r['cells'][$column] ?? '');
            }
            ++$row;
        }

        foreach (range(1, \count(self::HEADERS)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('A2');

        // Second sheet, added AFTER "Planning" is complete so that sheet is never touched.
        $this->appendTeamDayMatrix($spreadsheet, $data);

        return $this->toBinary($spreadsheet);
    }

    /**
     * Second sheet — a team × day matrix answering "l'équipe U13 s'entraîne quand ?"
     * at a glance : rows = teams, columns = the days actually used, cell = venue + time.
     *
     * No coach on purpose : it already lives in the Planning sheet and here it would only
     * clutter the scan (founder decision).
     *
     * Empty windows (`$data->emptySlots`) belong to no team, so they NEVER enter the matrix ;
     * they stay in the Planning sheet as "(vide)" rows.
     *
     * Not added at all when the placements span a single venue : the matrix exists to
     * disambiguate WHICH gym, and a venue-scoped export (all slots share the venue) or a
     * single-gym club has nothing to disambiguate — the Planning sheet already says it better.
     * The trigger reads the REALITY of the placements, not the `$venueId` argument nor
     * `$data->venues` (which always holds every club venue regardless of scope).
     *
     * ⚠ Same D-18 discipline as the Planning sheet : the model is indexed by (team, day) and
     * PROJECTED onto ordered rows/columns — never written positionally. A day column and its
     * cells are always looked up by the SAME day key, so no silent column-shift can happen.
     */
    private function appendTeamDayMatrix(Spreadsheet $spreadsheet, ScheduleExportData $data): void
    {
        $venueName = static fn (string $id): string => $data->venues[$id]['name'] ?? '';

        $venuesUsed = [];
        foreach ($data->slots as $slot) {
            $venuesUsed[$slot->getVenueId()] = true;
        }
        if (\count($venuesUsed) < 2) {
            return; // mono-gymnase en portée : la matrice n'apporte rien que la feuille Planning ne dise mieux.
        }

        // "Rang" cell built PAR ÉQUIPE : a sortable key whose ALPHABETICAL order reproduces the
        // PDF's rank order (S→A→B→C→D = ascending tierRank). A naive sort on the raw labels
        // « S, A, B, C, D » would give A, B, C, D, S — wrong — so the value leads with the
        // zero-padded numeric tierRank, whose string order IS the tierRank order (and stays right
        // past 9 tiers). A team with no rank gets an EMPTY cell : never an invented value, and
        // Excel always sorts blanks to the END, coherent with the PDF's PHP_INT_MAX default.
        $rankCell = static function (string $teamId) use ($data): string {
            $rank = $data->teamRanks[$teamId] ?? ['label' => '', 'name' => '', 'tierRank' => \PHP_INT_MAX, 'tierOrder' => \PHP_INT_MAX];

            return '' === $rank['label'] ? '' : \sprintf('%02d · %s', $rank['tierRank'], $rank['label']);
        };

        // Rows = EVERY team of the season : a team with no session is a hole in the planning, the
        // first thing a manager must see — it keeps its row with empty cells, it is never hidden.
        // No misleading empty row can leak in from a reduced scope : a venue-scoped export has a
        // single distinct venue and returns above, so the matrix only ever renders on a full
        // multi-venue club export, where "all season teams" is exactly the right set.
        /** @var array<string, array{name: string, category: string, rank: string}> $teams */
        $teams = [];
        foreach ($data->teamNames as $teamId => $name) {
            $teams[$teamId] = ['name' => $name, 'category' => $data->teamCategories[$teamId] ?? '', 'rank' => $rankCell($teamId)];
        }

        // Model indexed by (team, day), built from the placements.
        /** @var array<string, array<int, list<array{start: string, text: string}>>> $matrix */
        $matrix = [];
        $daysUsed = [];
        foreach ($data->slots as $slot) {
            $day = $slot->getDayOfWeek();
            $start = $slot->getStartTime()->format('H:i');
            // Never drop a slot silently : a team training twice the same day keeps BOTH entries.
            $matrix[$slot->getTeamId()][$day][] = ['start' => $start, 'text' => trim($venueName($slot->getVenueId()) . ' · ' . $start)];
            $daysUsed[$day] = true;
        }
        // Defence : a placement whose team is absent from teamNames (an anomaly) still keeps a
        // row — a slot never vanishes in silence.
        foreach (array_keys($matrix) as $teamId) {
            $teams[$teamId] ??= ['name' => $data->teamNames[$teamId] ?? '', 'category' => $data->teamCategories[$teamId] ?? '', 'rank' => $rankCell($teamId)];
        }

        // Columns = only the days actually used, kept in DAY_LABELS order (a training week is
        // usually Mon–Sat ; empty day columns would only add noise).
        $dayColumns = array_values(array_filter(
            array_keys(ScheduleExportData::DAY_LABELS),
            static fn (int $d): bool => isset($daysUsed[$d]),
        ));
        // Rows sorted by category then name, coherent with the on-screen order.
        uasort($teams, static fn (array $a, array $b): int => [$a['category'], $a['name']] <=> [$b['category'], $b['name']]);

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Équipes × jours');

        // Header : three fixed columns (Équipe, Catégorie, Rang) then one per day. Kept as a
        // name-indexed list so the day header and the cells beneath it are projected from the
        // SAME `$dayColumns` order.
        $sheet->setCellValue([1, 1], 'Équipe');
        $sheet->setCellValue([2, 1], 'Catégorie');
        $sheet->setCellValue([3, 1], 'Rang');
        $firstDayCol = 4;
        foreach ($dayColumns as $offset => $day) {
            $sheet->setCellValue([$firstDayCol + $offset, 1], ScheduleExportData::DAY_LABELS[$day]);
        }
        $lastCol = $firstDayCol + \count($dayColumns) - 1;

        $row = 2;
        foreach ($teams as $teamId => $meta) {
            $sheet->setCellValue([1, $row], $meta['name']);
            $sheet->setCellValue([2, $row], $meta['category']);
            $sheet->setCellValue([3, $row], $meta['rank']);
            foreach ($dayColumns as $offset => $day) {
                // Projection PAR (équipe, jour) : la cellule sous « Mardi » lit toujours le mardi
                // de CETTE équipe — jamais l'écriture positionnelle qui produirait un décalage muet.
                $entries = $matrix[$teamId][$day] ?? [];
                usort($entries, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
                $text = implode("\n", array_map(static fn (array $e): string => $e['text'], $entries));
                $sheet->setCellValue([$firstDayCol + $offset, $row], $text);
                if (str_contains($text, "\n")) {
                    $sheet->getStyle([$firstDayCol + $offset, $row])->getAlignment()->setWrapText(true);
                }
            }
            ++$row;
        }

        $headerStyle = $sheet->getStyle('A1:' . Coordinate::stringFromColumnIndex($lastCol) . '1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8E8E8');
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        foreach (range(1, $lastCol) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
        // Freeze the header row and the three identity columns (Équipe, Catégorie, Rang) so a
        // wide week stays readable.
        $sheet->freezePane('D2');
    }

    private function toBinary(Spreadsheet $spreadsheet): string
    {
        $writer = new Xlsx($spreadsheet);
        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            throw new RuntimeException('Cannot open temp stream for XLSX.');
        }
        $writer->save($stream);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return false === $content ? '' : $content;
    }
}
