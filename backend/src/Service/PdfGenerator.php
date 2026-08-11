<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Export\ExportEmptyWindow;
use App\Export\ScheduleExportData;
use App\Export\ScheduleExportDataProvider;
use App\Storage\LogoStorage;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use finfo;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Renders a weekly training schedule as a landscape grid (days × venues, time
 * down the rows) and hands the HTML to the Puppeteer worker.
 *
 * Section 1 (page 1) is the manager grid — the worker fits it to a single A4
 * landscape page (shrinking the font as needed) and shoots the PNG from it.
 * On a multi-venue export a second section is appended: a "team × day" matrix
 * (one line per team, grouped by rank) flowing onto page(s) 2+, so a manager can
 * hand each team the page that concerns it. The worker's multi-section mode is
 * opt-in and back-compatible — a single-section call produces the same one-page
 * PDF as before. Scope is either every venue or a single one; a single-venue (or
 * single-gym) export carries no matrix — nothing to disambiguate.
 */
class PdfGenerator
{
    private const PDF_WORKER_URL = 'http://pdf-worker:3000/generate';
    private const OUTPUT_DIR = '/app/backend/public/exports';
    private const PUBLIC_PATH = '/exports';
    // Match the on-screen planning grid's granularity (grid.ts stepMin = 15) so
    // slots starting at :15/:45 land on the right row instead of being snapped or
    // dropped into a coarser bucket.
    private const STEP_MINUTES = 15;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly ScheduleExportDataProvider $exportData,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly LogoStorage $logoStorage,
    ) {}

    /**
     * P4-52 — où les rendus atterrissent, et sous quelle URL ils sont servis.
     *
     * Exposés en accesseurs plutôt que recopiés dans `PurgeExportsCommand` : la purge doit
     * balayer EXACTEMENT le répertoire que ce générateur écrit. Deux constantes qui
     * dériveraient donneraient une purge qui ne trouve rien — et qui le dit en vert.
     */
    public static function outputDir(): string
    {
        return self::OUTPUT_DIR;
    }

    public static function publicPath(): string
    {
        return self::PUBLIC_PATH;
    }

    /**
     * @return array{pdf: string, png: ?string}
     */
    public function generate(Schedule $schedule, ?string $venueId = null): array
    {
        $data = $this->exportData->load($schedule, $venueId);
        // A "team × day" matrix is appended as a second section only on a genuinely
        // multi-venue export (see hasMatrix). That is exactly when the PDF becomes
        // multi-page, so the two facts are the same flag: it drives both the HTML
        // and the worker's multi-section paging.
        $multiSection = $this->hasMatrix($data);
        $html = $this->buildHtml($schedule, $data, $venueId, $multiSection);
        // Scope suffix keeps the all-venues and per-venue exports as distinct files.
        $scope = null === $venueId ? 'all' : substr($venueId, 0, 8);
        $pdfFilename = \sprintf('schedule-%s-%s.pdf', $schedule->getId(), $scope);
        $pngFilename = \sprintf('schedule-%s-%s.png', $schedule->getId(), $scope);

        // The Puppeteer worker (a separate container) both creates this dir and
        // writes the files, as its own uid — PHP only reads back the result, so we
        // do NOT pre-create it here (that left it owned by www-data, unwritable by
        // the worker, and forced a world-writable chmod).

        try {
            $this->callWorker($html, $pdfFilename, $multiSection);
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException('PDF worker unreachable: ' . $e->getMessage(), $e->getCode(), $e);
        }

        $pngPath = self::OUTPUT_DIR . '/' . $pngFilename;

        return [
            'pdf' => self::PUBLIC_PATH . '/' . $pdfFilename,
            'png' => is_file($pngPath) ? self::PUBLIC_PATH . '/' . $pngFilename : null,
        ];
    }

    private function callWorker(string $html, string $filename, bool $multiSection = false): void
    {
        // Single-section exports keep the EXACT historical payload (no extra key), so the
        // worker takes its unchanged one-page path and produces the same file as before.
        // The multi-section flag is added ONLY when a matrix section is present.
        $json = [
            'html' => $html,
            'filename' => $filename,
            'landscape' => true,
        ];
        if ($multiSection) {
            $json['multiSection'] = true;
        }

        $response = $this->httpClient->request('POST', self::PDF_WORKER_URL, [
            'json' => $json,
            'timeout' => 30,
        ]);

        $result = $response->toArray(false);

        if (!($result['success'] ?? false)) {
            throw new RuntimeException($result['error'] ?? 'Worker generation failed.');
        }
    }

    private function buildHtml(Schedule $schedule, ScheduleExportData $data, ?string $venueId, bool $multiSection = false): string
    {
        $slots = $data->slots;
        $teamNames = $data->teamNames;
        $venues = $data->venues;
        $coachNames = $data->coachNames;
        $club = $this->entityManager->getRepository(Club::class)->find($schedule->getClubId());

        // Columns = ordered (day, venue) pairs that actually carry a slot, so the
        // grid never shows an empty venue column. Header groups them by day.
        $present = [];
        foreach ($slots as $slot) {
            $present[$slot->getDayOfWeek()][$slot->getVenueId()] = true;
        }
        // Empty windows create their (day, venue) column too, so a venue used ONLY
        // for unfilled windows still gets a column of `vide` cells.
        foreach ($data->emptySlots as $window) {
            $present[$window->dayOfWeek][$window->venueId] = true;
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

        [$startMin, $endMin] = $this->timeBounds($slots, $data->emptySlots);

        // Index slots by column + start step. A step can hold MORE than one slot
        // (a split court runs two teams at the same time in the same gym), so we
        // keep a list per bucket instead of overwriting.
        $byColStep = [];
        foreach ($slots as $slot) {
            $key = $slot->getDayOfWeek() . '|' . $slot->getVenueId();
            $startStep = intdiv($this->minutesOf($slot->getStartTime()) - $startMin, self::STEP_MINUTES);
            $byColStep[$key][$startStep][] = $slot;
        }
        // Empty windows indexed by col+start step. Only one per (col, step) — two
        // unfilled windows starting in the same 15-min step (e.g. a split court)
        // collapse to a single `vide` cell (MVP, mirrors the capacity note above).
        $emptyByColStep = [];
        foreach ($data->emptySlots as $window) {
            $key = $window->dayOfWeek . '|' . $window->venueId;
            $startStep = intdiv($this->minutesOf($window->startTime) - $startMin, self::STEP_MINUTES);
            $emptyByColStep[$key][$startStep] = $window;
        }

        $steps = max(1, intdiv($endMin - $startMin, self::STEP_MINUTES));
        $rows = $this->buildRows($columns, $byColStep, $emptyByColStep, $startMin, $steps, $teamNames, $venues, $coachNames, null !== $venueId);
        $header = $this->buildHeader($columns, $venues);

        $scopeLabel = null === $venueId ? 'Tous les gymnases' : ($venues[$venueId]['name'] ?? 'Gymnase');
        // P4-52 — le logo du club en tête de PDF, INLINÉ en data URI : le worker
        // Puppeteer ne doit dépendre d'aucun fetch réseau (une URL /api/... peut
        // être injoignable depuis son contexte, et le document doit rester
        // auto-portant). Best-effort : pas de logo = un en-tête comme avant.
        $logoBytes = $club instanceof Club ? $this->logoStorage->read($club->getId()) : null;
        $logoImg = '';
        if (null !== $logoBytes && '' !== $logoBytes) {
            $mime = new finfo(\FILEINFO_MIME_TYPE)->buffer($logoBytes) ?: 'image/png';
            $logoImg = \sprintf('<img class="logo" src="data:%s;base64,%s" alt="">', $mime, base64_encode($logoBytes));
        }
        // ADR-0002 inv. 12 : le nom VIVANT du plan, jamais la photo `Schedule.name` prise à
        // la création de la version — sinon le document remis aux coachs contredit le nom que
        // l'écran affiche et celui du fichier qui le porte (revue #339 round 2).
        $planningName = $this->schedulePlanProvisioner->displayNameOf($schedule);
        $title = htmlspecialchars($club?->getName() ?? $planningName);

        $grid = [] === $columns
            ? '<p class="empty">Aucun créneau planifié.</p>'
            : \sprintf('<table><thead>%s</thead><tbody>%s</tbody></table>', $header, $rows);
        // Section 1 = the manager grid (page 1). The worker scales THIS section to one page
        // and shoots the PNG from it, exactly as before.
        $body = \sprintf('<section class="page-grid">%s</section>', $grid);
        // Section 2 = the "team × day" matrix, on its own page(s). Only present on a
        // multi-venue export — the same condition that set $multiSection.
        if ($multiSection) {
            $body .= $this->buildMatrixSection($data);
        }

        return $this->wrapDocument($title, htmlspecialchars($scopeLabel), htmlspecialchars($planningName), $body, $logoImg);
    }

    /**
     * @param list<array{day:int,venueId:string}>                   $columns
     * @param array<string, array<int, list<ScheduleSlotTemplate>>> $byColStep
     * @param array<string, array<int, ExportEmptyWindow>>          $emptyByColStep
     * @param array<string, string>                                 $teamNames
     * @param array<string, array{name:string,color:?string}>       $venues
     * @param array<string, string>                                 $coachNames
     */
    private function buildRows(array $columns, array $byColStep, array $emptyByColStep, int $startMin, int $steps, array $teamNames, array $venues, array $coachNames, bool $singleVenue): string
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
                $colKey = $col['day'] . '|' . $col['venueId'];
                $bucket = $byColStep[$colKey][$step] ?? [];
                if ([] === $bucket) {
                    // A defined-but-unfilled window here → a `vide` cell spanning its
                    // duration; otherwise a blank gap.
                    $window = $emptyByColStep[$colKey][$step] ?? null;
                    if (null !== $window) {
                        // Grow the `vide` cell over its duration but NEVER across an
                        // already-covered step or a real placement below it — a rowspan
                        // that covered a placement's step would skip (drop) that cell.
                        $maxSpan = max(1, (int) ceil($window->durationMinutes / self::STEP_MINUTES));
                        $span = 1;
                        while ($span < $maxSpan && !isset($covered[$colIndex][$step + $span]) && [] === ($byColStep[$colKey][$step + $span] ?? [])) {
                            ++$span;
                        }
                        for ($k = 1; $k < $span; ++$k) {
                            $covered[$colIndex][$step + $k] = true;
                        }
                        $cells .= \sprintf('<td class="cell empty" rowspan="%d">vide</td>', $span);

                        continue;
                    }
                    $cells .= '<td class="cell"></td>';

                    continue;
                }
                // Cell spans the longest concurrent session in this bucket.
                $span = 1;
                foreach ($bucket as $slot) {
                    $span = max($span, (int) ceil($slot->getDurationMinutes() / self::STEP_MINUTES));
                }
                for ($k = 1; $k < $span; ++$k) {
                    $covered[$colIndex][$step + $k] = true;
                }
                $cells .= $this->slotCell($bucket, $span, $teamNames, $venues, $coachNames, $singleVenue);
            }
            $rows .= \sprintf('<tr><th class="time">%s</th>%s</tr>', $timeLabel, $cells);
        }

        return $rows;
    }

    /**
     * @param list<ScheduleSlotTemplate>                      $bucket     concurrent slots (≥1) in one cell
     * @param array<string, string>                           $teamNames
     * @param array<string, array{name:string,color:?string}> $venues
     * @param array<string, string>                           $coachNames
     */
    private function slotCell(array $bucket, int $span, array $teamNames, array $venues, array $coachNames, bool $singleVenue): string
    {
        // Colour the cell by the (shared) venue of the bucket; stack each team.
        $venue = $venues[$bucket[0]->getVenueId()] ?? ['name' => 'Salle', 'color' => null];
        $color = $venue['color'] ?? '#666666';
        $fg = $this->readableForeground($color);

        $entries = '';
        foreach ($bucket as $slot) {
            $teamName = $teamNames[$slot->getTeamId()] ?? 'Équipe inconnue';
            $coachId = $slot->getCoachId();
            $coachName = null !== $coachId ? ($coachNames[$coachId] ?? null) : null;
            // In a single-venue export the venue name is redundant (it's in the title).
            $sub = $singleVenue ? ($coachName ?? '') : trim($venue['name'] . (null !== $coachName ? ' · ' . $coachName : ''));
            $entries .= \sprintf(
                '<div class="entry"><span class="team">%s</span>%s</div>',
                htmlspecialchars($teamName),
                '' === $sub ? '' : \sprintf('<span class="sub">%s</span>', htmlspecialchars($sub)),
            );
        }

        return \sprintf(
            '<td class="cell filled" rowspan="%d" style="background:%s;color:%s">%s</td>',
            $span,
            htmlspecialchars($color),
            htmlspecialchars($fg),
            $entries,
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
            $dayRow .= \sprintf('<th class="day" colspan="%d">%s</th>', $count, htmlspecialchars(ScheduleExportData::DAY_LABELS[$day] ?? ''));
        }
        $venueRow = '<th class="corner"></th>';
        foreach ($columns as $col) {
            // Colour each venue header with its own colour (same mapping as the
            // cells) instead of the flat grey; readable text on top.
            $venue = $venues[$col['venueId']] ?? ['name' => '', 'color' => null];
            $color = $venue['color'] ?? null;
            $style = null === $color ? '' : \sprintf(' style="background:%s;color:%s"', htmlspecialchars($color), htmlspecialchars($this->readableForeground($color)));
            $venueRow .= \sprintf('<th class="venue"%s>%s</th>', $style, htmlspecialchars($venue['name']));
        }

        return \sprintf('<tr>%s</tr><tr>%s</tr>', $dayRow, $venueRow);
    }

    /**
     * Whether the "team × day" matrix (section 2) is worth appending.
     *
     * Same trigger as the XLSX second sheet (SpreadsheetGenerator::appendTeamDayMatrix): the
     * matrix exists to disambiguate WHICH gym, so it only makes sense once the placements span
     * ≥ 2 distinct venues. It reads the REALITY of the placements — never the $venueId argument
     * nor $data->venues, which always holds every club venue regardless of scope.
     */
    private function hasMatrix(ScheduleExportData $data): bool
    {
        $venuesUsed = [];
        foreach ($data->slots as $slot) {
            $venuesUsed[$slot->getVenueId()] = true;
        }

        return \count($venuesUsed) >= 2;
    }

    /**
     * Section 2 — a one-line-per-team matrix (rows = teams, columns = the days actually used,
     * cell = gym + time, NO coach) so a manager can hand each team the page that concerns it.
     *
     * Mirrors the XLSX second sheet's data rules (empty windows belong to no team and never
     * enter it; a team with no session keeps an empty row — the hole a manager must see; a
     * team training twice a day keeps both entries). It diverges from the XLSX on ROW ORDER on
     * purpose (founder-frozen for the PDF): rows are grouped by priority tier S→A→B→C→D, each
     * group carrying a subtitle and kept atomic in landscape pagination (tbody break-inside:
     * avoid), whereas the XLSX sheet sorts by category then name.
     *
     * Assumes hasMatrix($data) is true (caller-guarded): it still renders every season team.
     */
    private function buildMatrixSection(ScheduleExportData $data): string
    {
        $venueName = static fn (string $id): string => $data->venues[$id]['name'] ?? '';
        $venueColor = static fn (string $id): string => $data->venues[$id]['color'] ?? '#666666';

        // Model indexed by (team, day) from the placements; each entry carries its own venue
        // colour so a cell stacking two sessions colours each like the grid does.
        /** @var array<string, array<int, list<array{start:string, text:string, color:string}>>> $matrix */
        $matrix = [];
        $daysUsed = [];
        foreach ($data->slots as $slot) {
            $day = $slot->getDayOfWeek();
            $start = $slot->getStartTime()->format('H:i');
            $venueId = $slot->getVenueId();
            // No coach on purpose (founder decision): it already lives in the grid and would
            // only clutter the scan.
            $matrix[$slot->getTeamId()][$day][] = [
                'start' => $start,
                'text' => trim($venueName($venueId) . ' · ' . $start),
                'color' => $venueColor($venueId),
            ];
            $daysUsed[$day] = true;
        }

        // Rows = EVERY season team (a team with no session is a hole to surface, never hidden),
        // plus any placement whose team is missing from teamNames (anomaly) so no slot vanishes.
        /** @var array<string, array{name:string, label:string, subtitle:string, tierRank:int, tierOrder:int}> $teams */
        $teams = [];
        $teamIds = array_keys($data->teamNames + $matrix);
        foreach ($teamIds as $teamId) {
            $rank = $data->teamRanks[$teamId] ?? ['label' => '', 'name' => '', 'tierRank' => \PHP_INT_MAX, 'tierOrder' => \PHP_INT_MAX];
            $subtitle = trim($rank['label'] . ('' !== $rank['name'] ? ' · ' . $rank['name'] : ''));
            $teams[$teamId] = [
                'name' => $data->teamNames[$teamId] ?? '',
                'label' => $rank['label'],
                'subtitle' => '' === $subtitle ? 'Sans rang' : $subtitle,
                'tierRank' => $rank['tierRank'],
                'tierOrder' => $rank['tierOrder'],
            ];
        }

        // Columns = only the days actually used, kept in DAY_LABELS order (Sam/Dim appear only
        // when occupied). Group teams by tier (S→A→B→C→D = ascending tierRank), then by their
        // in-tier order, then name — the global rank the manager sees on screen.
        $dayColumns = array_values(array_filter(
            array_keys(ScheduleExportData::DAY_LABELS),
            static fn (int $d): bool => isset($daysUsed[$d]),
        ));
        uasort($teams, static fn (array $a, array $b): int => [$a['tierRank'], $a['tierOrder'], $a['name']] <=> [$b['tierRank'], $b['tierOrder'], $b['name']]);

        $colspan = 1 + \count($dayColumns);
        $head = '<tr><th class="team-col">Équipe</th>';
        foreach ($dayColumns as $day) {
            $head .= \sprintf('<th>%s</th>', htmlspecialchars(ScheduleExportData::DAY_LABELS[$day]));
        }
        $head .= '</tr>';

        // One <tbody class="rank-group"> per tier: break-inside:avoid keeps a whole group on a
        // single page, so a rank is never split by landscape pagination.
        $groups = '';
        $currentRank = null;
        $groupRows = '';
        $groupSubtitle = '';
        foreach ($teams as $teamId => $meta) {
            if ($meta['tierRank'] !== $currentRank) {
                $groups .= $this->renderRankGroup($colspan, $groupSubtitle, $groupRows);
                $currentRank = $meta['tierRank'];
                $groupSubtitle = $meta['subtitle'];
                $groupRows = '';
            }
            $groupRows .= $this->matrixRow($teamId, $meta['name'], $matrix, $dayColumns);
        }
        $groups .= $this->renderRankGroup($colspan, $groupSubtitle, $groupRows);

        return \sprintf(
            '<section class="page-matrix"><h2 class="matrix-title">Par équipe — quand chaque équipe s’entraîne</h2>'
            . '<table class="matrix"><thead>%s</thead>%s</table></section>',
            $head,
            $groups,
        );
    }

    /** One rank group as a break-inside-safe tbody (subtitle row + its team rows); '' when empty. */
    private function renderRankGroup(int $colspan, string $subtitle, string $rows): string
    {
        if ('' === $rows) {
            return '';
        }

        return \sprintf(
            '<tbody class="rank-group"><tr class="rank-title"><th colspan="%d">%s</th></tr>%s</tbody>',
            $colspan,
            htmlspecialchars($subtitle),
            $rows,
        );
    }

    /**
     * One matrix row for a team, projected by (teamId, day) — the cell under "Mardi" always
     * reads THIS team's Tuesday, never a positional neighbour (same D-18 discipline as the XLSX).
     *
     * @param array<string, array<int, list<array{start:string, text:string, color:string}>>> $matrix
     * @param list<int>                                                                       $dayColumns
     */
    private function matrixRow(string $teamId, string $teamName, array $matrix, array $dayColumns): string
    {
        $cells = \sprintf('<th class="team-col">%s</th>', htmlspecialchars($teamName));
        foreach ($dayColumns as $day) {
            $entries = $matrix[$teamId][$day] ?? [];
            usort($entries, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
            $chips = '';
            foreach ($entries as $entry) {
                $color = $entry['color'];
                $chips .= \sprintf(
                    '<span class="chip" style="background:%s;color:%s">%s</span>',
                    htmlspecialchars($color),
                    htmlspecialchars($this->readableForeground($color)),
                    htmlspecialchars($entry['text']),
                );
            }
            $cells .= \sprintf('<td>%s</td>', $chips);
        }

        return \sprintf('<tr>%s</tr>', $cells);
    }

    /**
     * @param array<ScheduleSlotTemplate> $slots
     * @param list<ExportEmptyWindow>     $emptySlots
     *
     * @return array{0:int,1:int} floor(min start)/ceil(max end) to the hour
     */
    private function timeBounds(array $slots, array $emptySlots = []): array
    {
        if ([] === $slots && [] === $emptySlots) {
            return [17 * 60, 21 * 60];
        }
        $min = \PHP_INT_MAX;
        $max = 0;
        foreach ($slots as $slot) {
            $start = $this->minutesOf($slot->getStartTime());
            $min = min($min, $start);
            $max = max($max, $start + $slot->getDurationMinutes());
        }
        foreach ($emptySlots as $window) {
            $start = $this->minutesOf($window->startTime);
            $min = min($min, $start);
            $max = max($max, $start + $window->durationMinutes);
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

    private function wrapDocument(string $title, string $scopeLabel, string $scheduleName, string $body, string $logoImg = ''): string
    {
        return \sprintf(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
                * { box-sizing: border-box; }
                body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 10px; color: #111; }
                header { display: flex; align-items: baseline; gap: 12px; margin-bottom: 8px; border-bottom: 2px solid #111; padding-bottom: 6px; }
                header h1 { font-size: 16px; margin: 0; }
                header .logo { height: 26px; width: 26px; object-fit: contain; align-self: center; }
                header .scope { font-size: 12px; color: #333; font-weight: bold; }
                header .sched { font-size: 11px; color: #777; margin-left: auto; }
                table { width: 100%%; border-collapse: collapse; table-layout: fixed; }
                th, td { border: 1px solid #bbb; padding: 2px 3px; font-size: 9px; vertical-align: top; }
                thead th { background: #f0f0f0; text-align: center; }
                th.day { font-size: 10px; }
                th.corner { width: 34px; background: #fff; border: none; }
                th.time { background: #fafafa; text-align: right; white-space: nowrap; font-weight: normal; color: #555; width: 34px; }
                td.cell { height: 14px; }
                td.filled .entry + .entry { margin-top: 2px; padding-top: 2px; border-top: 1px dashed rgba(255,255,255,0.45); }
                td.filled .team { display: block; font-weight: bold; line-height: 1.1; }
                td.filled .sub { display: block; font-size: 8px; opacity: 0.9; line-height: 1.1; }
                .empty { color: #999; font-style: italic; }
                td.cell.empty { text-align: center; vertical-align: middle; color: #999; font-style: italic; font-size: 8px; border: 1px dashed #ccc; }
                /* Section 2 — the team × day matrix, on its own page(s). */
                .page-matrix { break-before: page; }
                .matrix-title { font-size: 13px; margin: 0 0 8px; }
                table.matrix td { text-align: left; height: 16px; }
                table.matrix .team-col { text-align: left; font-weight: bold; width: 92px; background: #fafafa; }
                /* A whole rank group stays on one page — never split by landscape pagination. */
                table.matrix tbody.rank-group { break-inside: avoid; }
                table.matrix tr.rank-title th { text-align: left; background: #e9e9ee; color: #222; font-size: 10px; padding: 3px 4px; border-top: 2px solid #999; }
                table.matrix .chip { display: inline-block; padding: 1px 4px; margin: 1px; border-radius: 3px; font-size: 8px; line-height: 1.3; white-space: nowrap; }
            </style></head><body>
                <header>%s<h1>%s</h1><span class="scope">%s</span><span class="sched">%s</span></header>
                %s
            </body></html>',
            $logoImg,
            $title,
            $scopeLabel,
            $scheduleName,
            $body,
        );
    }
}
