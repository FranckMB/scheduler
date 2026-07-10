<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Export\ExportEmptyWindow;
use App\Export\ScheduleExportData;
use App\Export\ScheduleExportDataProvider;
use DateInterval;
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
        /** @var list<array{day:int, start:string, venue:string, values:list<string>}> $rows */
        $rows = [];
        foreach ($data->slots as $slot) {
            $start = $slot->getStartTime();
            $end = $start->add(new DateInterval('PT' . $slot->getDurationMinutes() . 'M'));
            $rows[] = [
                'day' => $slot->getDayOfWeek(), 'start' => $start->format('H:i'), 'venue' => $venueName($slot->getVenueId()),
                'values' => [
                    ScheduleExportData::DAY_LABELS[$slot->getDayOfWeek()] ?? '',
                    $start->format('H:i'), $end->format('H:i'), $venueName($slot->getVenueId()),
                    $data->teamNames[$slot->getTeamId()] ?? '',
                    $data->teamCategories[$slot->getTeamId()] ?? '',
                    null !== $slot->getCoachId() ? ($data->coachNames[$slot->getCoachId()] ?? '') : '',
                ],
            ];
        }
        foreach ($data->emptySlots as $window) {
            $end = $window->startTime->add(new DateInterval('PT' . $window->durationMinutes . 'M'));
            $rows[] = [
                'day' => $window->dayOfWeek, 'start' => $window->startTime->format('H:i'), 'venue' => $venueName($window->venueId),
                'values' => [
                    ScheduleExportData::DAY_LABELS[$window->dayOfWeek] ?? '',
                    $window->startTime->format('H:i'), $end->format('H:i'), $venueName($window->venueId),
                    '(vide)', '', '',
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
        $headerStyle = $sheet->getStyle('A1:G1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8E8E8');

        $row = 2;
        foreach ($rows as $r) {
            foreach ($r['values'] as $i => $value) {
                $sheet->setCellValue([$i + 1, $row], $value);
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
