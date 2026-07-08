<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\Venue;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Renders a weekly training schedule as a one-page landscape grid (days ×
 * venues, time down the rows) and hands the HTML to the Puppeteer worker, which
 * fits it to a single A4 landscape page (shrinking the font as needed — a
 * multi-page training week is not useful). Scope is either every venue or a
 * single one (venue-only export, per product: no per-team / per-coach view).
 */
class PdfGenerator
{
    private const PDF_WORKER_URL = 'http://pdf-worker:3000/generate';
    private const OUTPUT_DIR = '/app/backend/public/exports';
    private const PUBLIC_PATH = '/exports';
    private const STEP_MINUTES = 30;

    /** Monday→Sunday; dayOfWeek is 1..7 ISO (the training week is usually 1..6). */
    private const DAY_LABELS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
    ) {}

    /**
     * @return array{pdf: string, png: ?string}
     */
    public function generate(Schedule $schedule, ?string $venueId = null): array
    {
        $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
            'scheduleId' => $schedule->getId(),
        ]);
        if (null !== $venueId) {
            $slots = array_values(array_filter($slots, static fn (ScheduleSlotTemplate $s): bool => $s->getVenueId() === $venueId));
        }

        $html = $this->buildHtml($schedule, $slots, $venueId);
        // Scope suffix keeps the all-venues and per-venue exports as distinct files.
        $scope = null === $venueId ? 'all' : substr($venueId, 0, 8);
        $pdfFilename = \sprintf('schedule-%s-%s.pdf', $schedule->getId(), $scope);
        $pngFilename = \sprintf('schedule-%s-%s.png', $schedule->getId(), $scope);

        // The Puppeteer worker (a separate container, different uid) writes the
        // PDF/PNG into this shared volume, so the dir must be writable by it too.
        if (!is_dir(self::OUTPUT_DIR)) {
            mkdir(self::OUTPUT_DIR, 0o777, true);
        }
        @chmod(self::OUTPUT_DIR, 0o777);

        try {
            $this->callWorker($html, $pdfFilename);
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException('PDF worker unreachable: ' . $e->getMessage(), $e->getCode(), $e);
        }

        $pngPath = self::OUTPUT_DIR . '/' . $pngFilename;

        return [
            'pdf' => self::PUBLIC_PATH . '/' . $pdfFilename,
            'png' => is_file($pngPath) ? self::PUBLIC_PATH . '/' . $pngFilename : null,
        ];
    }

    private function callWorker(string $html, string $filename): void
    {
        $response = $this->httpClient->request('POST', self::PDF_WORKER_URL, [
            'json' => [
                'html' => $html,
                'filename' => $filename,
                'landscape' => true,
            ],
            'timeout' => 30,
        ]);

        $result = $response->toArray(false);

        if (!($result['success'] ?? false)) {
            throw new RuntimeException($result['error'] ?? 'Worker generation failed.');
        }
    }

    /**
     * @param array<ScheduleSlotTemplate> $slots
     */
    private function buildHtml(Schedule $schedule, array $slots, ?string $venueId): string
    {
        $teamNames = $this->getTeamNames($schedule);
        $venues = $this->getVenues($schedule);
        $coachNames = $this->getCoachNames($schedule);
        $club = $this->entityManager->getRepository(Club::class)->find($schedule->getClubId());

        // Columns = ordered (day, venue) pairs that actually carry a slot, so the
        // grid never shows an empty venue column. Header groups them by day.
        $present = [];
        foreach ($slots as $slot) {
            $present[$slot->getDayOfWeek()][$slot->getVenueId()] = true;
        }
        ksort($present);
        /** @var list<array{day:int,venueId:string}> $columns */
        $columns = [];
        foreach ($present as $day => $venueIds) {
            $ids = array_keys($venueIds);
            usort($ids, static fn (string $a, string $b): int => ($venues[$a]['name'] ?? '') <=> ($venues[$b]['name'] ?? ''));
            foreach ($ids as $vId) {
                $columns[] = ['day' => $day, 'venueId' => $vId];
            }
        }

        [$startMin, $endMin] = $this->timeBounds($slots);

        // Index slots by column + start step for O(1) placement.
        $byColStep = [];
        foreach ($slots as $slot) {
            $key = $slot->getDayOfWeek() . '|' . $slot->getVenueId();
            $startStep = intdiv($this->minutesOf($slot->getStartTime()) - $startMin, self::STEP_MINUTES);
            $byColStep[$key][$startStep] = $slot;
        }

        $steps = max(1, intdiv($endMin - $startMin, self::STEP_MINUTES));
        $rows = $this->buildRows($columns, $byColStep, $startMin, $steps, $teamNames, $venues, $coachNames, null !== $venueId);
        $header = $this->buildHeader($columns, $venues);

        $scopeLabel = null === $venueId ? 'Tous les gymnases' : ($venues[$venueId]['name'] ?? 'Gymnase');
        $title = htmlspecialchars($club?->getName() ?? $schedule->getName());

        $body = [] === $columns
            ? '<p class="empty">Aucun créneau planifié.</p>'
            : \sprintf('<table><thead>%s</thead><tbody>%s</tbody></table>', $header, $rows);

        return $this->wrapDocument($title, htmlspecialchars($scopeLabel), htmlspecialchars($schedule->getName()), $body);
    }

    /**
     * @param list<array{day:int,venueId:string}>             $columns
     * @param array<string, array<int, ScheduleSlotTemplate>> $byColStep
     * @param array<string, string>                           $teamNames
     * @param array<string, array{name:string,color:?string}> $venues
     * @param array<string, string>                           $coachNames
     */
    private function buildRows(array $columns, array $byColStep, int $startMin, int $steps, array $teamNames, array $venues, array $coachNames, bool $singleVenue): string
    {
        // covered[colIndex][step] = true when a rowspan from above occupies the cell.
        $covered = [];
        $rows = '';
        for ($step = 0; $step < $steps; ++$step) {
            $timeLabel = $this->formatMinutes($startMin + $step * self::STEP_MINUTES);
            $cells = '';
            foreach ($columns as $colIndex => $col) {
                if (isset($covered[$colIndex][$step])) {
                    continue;
                }
                $slot = $byColStep[$col['day'] . '|' . $col['venueId']][$step] ?? null;
                if (!$slot instanceof ScheduleSlotTemplate) {
                    $cells .= '<td class="cell"></td>';

                    continue;
                }
                $span = max(1, (int) ceil($slot->getDurationMinutes() / self::STEP_MINUTES));
                for ($k = 1; $k < $span; ++$k) {
                    $covered[$colIndex][$step + $k] = true;
                }
                $cells .= $this->slotCell($slot, $span, $teamNames, $venues, $coachNames, $singleVenue);
            }
            $rows .= \sprintf('<tr><th class="time">%s</th>%s</tr>', $timeLabel, $cells);
        }

        return $rows;
    }

    /**
     * @param array<string, string>                           $teamNames
     * @param array<string, array{name:string,color:?string}> $venues
     * @param array<string, string>                           $coachNames
     */
    private function slotCell(ScheduleSlotTemplate $slot, int $span, array $teamNames, array $venues, array $coachNames, bool $singleVenue): string
    {
        $teamName = $teamNames[$slot->getTeamId()] ?? 'Équipe inconnue';
        $venue = $venues[$slot->getVenueId()] ?? ['name' => 'Salle', 'color' => null];
        $color = $venue['color'] ?? '#666666';
        $fg = $this->readableForeground($color);
        $coachId = $slot->getCoachId();
        $coachName = null !== $coachId ? ($coachNames[$coachId] ?? null) : null;

        // In a single-venue export the venue name is redundant (it's in the title).
        $sub = $singleVenue ? ($coachName ?? '') : trim($venue['name'] . (null !== $coachName ? ' · ' . $coachName : ''));

        return \sprintf(
            '<td class="cell filled" rowspan="%d" style="background:%s;color:%s"><span class="team">%s</span>%s</td>',
            $span,
            htmlspecialchars($color),
            htmlspecialchars($fg),
            htmlspecialchars($teamName),
            '' === $sub ? '' : \sprintf('<span class="sub">%s</span>', htmlspecialchars($sub)),
        );
    }

    /**
     * @param list<array{day:int,venueId:string}>             $columns
     * @param array<string, array{name:string,color:?string}> $venues
     */
    private function buildHeader(array $columns, array $venues): string
    {
        // Row 1: day labels spanning their venue columns. Row 2: venue names.
        $dayGroups = [];
        foreach ($columns as $col) {
            $dayGroups[$col['day']] = ($dayGroups[$col['day']] ?? 0) + 1;
        }
        $dayRow = '<th class="corner"></th>';
        foreach ($dayGroups as $day => $count) {
            $dayRow .= \sprintf('<th class="day" colspan="%d">%s</th>', $count, htmlspecialchars(self::DAY_LABELS[$day] ?? ''));
        }
        $venueRow = '<th class="corner"></th>';
        foreach ($columns as $col) {
            $venueRow .= \sprintf('<th class="venue">%s</th>', htmlspecialchars($venues[$col['venueId']]['name'] ?? ''));
        }

        return \sprintf('<tr>%s</tr><tr>%s</tr>', $dayRow, $venueRow);
    }

    /**
     * @param array<ScheduleSlotTemplate> $slots
     *
     * @return array{0:int,1:int} floor(min start)/ceil(max end) to the hour
     */
    private function timeBounds(array $slots): array
    {
        if ([] === $slots) {
            return [17 * 60, 21 * 60];
        }
        $min = \PHP_INT_MAX;
        $max = 0;
        foreach ($slots as $slot) {
            $start = $this->minutesOf($slot->getStartTime());
            $min = min($min, $start);
            $max = max($max, $start + $slot->getDurationMinutes());
        }

        return [intdiv($min, 60) * 60, (int) (ceil($max / 60) * 60)];
    }

    private function minutesOf(DateTimeImmutable $time): int
    {
        return 60 * (int) $time->format('G') + (int) $time->format('i');
    }

    private function formatMinutes(int $total): string
    {
        return \sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    /** Near-black or white text for legible contrast on a coloured cell (WCAG-ish). */
    private function readableForeground(string $hex): string
    {
        if (1 !== preg_match('/^#([0-9a-fA-F]{6})$/', $hex, $m)) {
            return '#ffffff';
        }
        $n = (int) hexdec($m[1]);
        $lin = static function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $lum = 0.2126 * $lin(($n >> 16) & 0xFF) + 0.7152 * $lin(($n >> 8) & 0xFF) + 0.0722 * $lin($n & 0xFF);

        return $lum > 0.42 ? '#0b0b0c' : '#ffffff';
    }

    private function wrapDocument(string $title, string $scopeLabel, string $scheduleName, string $body): string
    {
        return \sprintf(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
                * { box-sizing: border-box; }
                body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 10px; color: #111; }
                header { display: flex; align-items: baseline; gap: 12px; margin-bottom: 8px; border-bottom: 2px solid #111; padding-bottom: 6px; }
                header h1 { font-size: 16px; margin: 0; }
                header .scope { font-size: 12px; color: #333; font-weight: bold; }
                header .sched { font-size: 11px; color: #777; margin-left: auto; }
                table { width: 100%%; border-collapse: collapse; table-layout: fixed; }
                th, td { border: 1px solid #bbb; padding: 2px 3px; font-size: 9px; vertical-align: top; }
                thead th { background: #f0f0f0; text-align: center; }
                th.day { font-size: 10px; }
                th.corner { width: 34px; background: #fff; border: none; }
                th.time { background: #fafafa; text-align: right; white-space: nowrap; font-weight: normal; color: #555; width: 34px; }
                td.cell { height: 14px; }
                td.filled .team { display: block; font-weight: bold; line-height: 1.1; }
                td.filled .sub { display: block; font-size: 8px; opacity: 0.9; line-height: 1.1; }
                .empty { color: #999; font-style: italic; }
            </style></head><body>
                <header><h1>%s</h1><span class="scope">%s</span><span class="sched">%s</span></header>
                %s
            </body></html>',
            $title,
            $scopeLabel,
            $scheduleName,
            $body,
        );
    }

    /**
     * @return array<string, string>
     */
    private function getTeamNames(Schedule $schedule): array
    {
        $teams = $this->entityManager->getRepository(Team::class)->findBy([
            'clubId' => $schedule->getClubId(),
            'seasonId' => $schedule->getSeasonId(),
        ]);

        $names = [];
        foreach ($teams as $team) {
            $names[$team->getId()] = $team->getName();
        }

        return $names;
    }

    /**
     * @return array<string, array{name:string,color:?string}>
     */
    private function getVenues(Schedule $schedule): array
    {
        $venues = $this->entityManager->getRepository(Venue::class)->findBy([
            'clubId' => $schedule->getClubId(),
            'seasonId' => $schedule->getSeasonId(),
        ]);

        $map = [];
        foreach ($venues as $venue) {
            $map[$venue->getId()] = ['name' => $venue->getName(), 'color' => $venue->getColor()];
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function getCoachNames(Schedule $schedule): array
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
