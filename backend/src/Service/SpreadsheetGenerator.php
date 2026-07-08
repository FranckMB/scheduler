<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\Venue;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
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
    private const DAY_LABELS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
    private const HEADERS = ['Jour', 'Début', 'Fin', 'Gymnase', 'Équipe', 'Catégorie', 'Coach'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /** @return string binary .xlsx content */
    public function generate(Schedule $schedule, ?string $venueId = null): string
    {
        $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
            'scheduleId' => $schedule->getId(),
        ]);
        if (null !== $venueId) {
            $slots = array_values(array_filter($slots, static fn (ScheduleSlotTemplate $s): bool => $s->getVenueId() === $venueId));
        }

        [$teamNames, $teamCategories] = $this->teamLookups($schedule);
        $venueNames = $this->venueNames($schedule);
        $coachNames = $this->coachNames($schedule);

        usort($slots, static fn (ScheduleSlotTemplate $a, ScheduleSlotTemplate $b): int => [$a->getDayOfWeek(), $a->getStartTime()->format('H:i'), $venueNames[$a->getVenueId()] ?? '']
                <=> [$b->getDayOfWeek(), $b->getStartTime()->format('H:i'), $venueNames[$b->getVenueId()] ?? '']);

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
        foreach ($slots as $slot) {
            $start = $slot->getStartTime();
            $end = $start->add(new DateInterval('PT' . $slot->getDurationMinutes() . 'M'));
            $values = [
                self::DAY_LABELS[$slot->getDayOfWeek()] ?? '',
                $start->format('H:i'),
                $end->format('H:i'),
                $venueNames[$slot->getVenueId()] ?? '',
                $teamNames[$slot->getTeamId()] ?? '',
                $teamCategories[$slot->getTeamId()] ?? '',
                null !== $slot->getCoachId() ? ($coachNames[$slot->getCoachId()] ?? '') : '',
            ];
            foreach ($values as $i => $value) {
                $sheet->setCellValue([$i + 1, $row], $value);
            }
            ++$row;
        }

        foreach (range(1, \count(self::HEADERS)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
        $sheet->getStyle('A1:G1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
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

    /**
     * @return array{0: array<string,string>, 1: array<string,string>} names, categories
     */
    private function teamLookups(Schedule $schedule): array
    {
        $teams = $this->entityManager->getRepository(Team::class)->findBy([
            'clubId' => $schedule->getClubId(),
            'seasonId' => $schedule->getSeasonId(),
        ]);
        $categories = $this->entityManager->getRepository(\App\Entity\SportCategory::class)->findBy([
            'clubId' => $schedule->getClubId(),
        ]);
        $categoryNames = [];
        foreach ($categories as $category) {
            $categoryNames[$category->getId()] = $category->getName();
        }

        $names = [];
        $teamCategories = [];
        foreach ($teams as $team) {
            $names[$team->getId()] = $team->getName();
            $teamCategories[$team->getId()] = $categoryNames[$team->getSportCategoryId()] ?? '';
        }

        return [$names, $teamCategories];
    }

    /**
     * @return array<string, string>
     */
    private function venueNames(Schedule $schedule): array
    {
        $venues = $this->entityManager->getRepository(Venue::class)->findBy([
            'clubId' => $schedule->getClubId(),
            'seasonId' => $schedule->getSeasonId(),
        ]);
        $names = [];
        foreach ($venues as $venue) {
            $names[$venue->getId()] = $venue->getName();
        }

        return $names;
    }

    /**
     * @return array<string, string>
     */
    private function coachNames(Schedule $schedule): array
    {
        $coaches = $this->entityManager->getRepository(Coach::class)->findBy([
            'clubId' => $schedule->getClubId(),
            'seasonId' => $schedule->getSeasonId(),
        ]);
        $names = [];
        foreach ($coaches as $coach) {
            $names[$coach->getId()] = trim(\sprintf('%s %s', $coach->getFirstName(), $coach->getLastName()));
        }

        return $names;
    }
}
