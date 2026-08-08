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
    private const HEADERS = ['Jour', 'Début', 'Fin', 'Gymnase', 'Équipe', 'Catégorie', 'Coach'];

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

        return $this->toBinary($spreadsheet);
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
