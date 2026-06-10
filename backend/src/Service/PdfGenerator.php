<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PdfGenerator
{
    private const PDF_WORKER_URL = 'http://pdf-worker:3000/generate';
    private const OUTPUT_DIR = '/app/backend/public/exports';
    private const PUBLIC_PATH = '/exports';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function generate(Schedule $schedule): string
    {
        $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
            'scheduleId' => $schedule->getId(),
        ]);

        $html = $this->buildHtml($schedule, $slots);
        $filename = sprintf('schedule-%s.pdf', $schedule->getId());

        if (!is_dir(self::OUTPUT_DIR)) {
            mkdir(self::OUTPUT_DIR, 0755, true);
        }

        try {
            $response = $this->httpClient->request('POST', self::PDF_WORKER_URL, [
                'json' => [
                    'html' => $html,
                    'filename' => $filename,
                ],
                'timeout' => 30,
            ]);

            $result = $response->toArray(false);

            if (!($result['success'] ?? false)) {
                throw new \RuntimeException($result['error'] ?? 'PDF generation failed.');
            }
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('PDF worker unreachable: '.$e->getMessage(), $e->getCode(), $e);
        }

        return self::PUBLIC_PATH.'/'.$filename;
    }

    /**
     * @param array<ScheduleSlotTemplate> $slots
     */
    private function buildHtml(Schedule $schedule, array $slots): string
    {
        $days = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

        $slotsByDay = [];
        foreach ($slots as $slot) {
            $day = $slot->getDayOfWeek();
            if (!isset($slotsByDay[$day])) {
                $slotsByDay[$day] = [];
            }
            $slotsByDay[$day][] = $slot;
        }

        foreach ($slotsByDay as $day => $daySlots) {
            usort($daySlots, static fn (ScheduleSlotTemplate $a, ScheduleSlotTemplate $b): int => $a->getStartTime() <=> $b->getStartTime());
            $slotsByDay[$day] = $daySlots;
        }

        $teamNames = $this->getTeamNames($schedule);
        $venueNames = $this->getVenueNames($schedule);
        $coachNames = $this->getCoachNames($schedule);

        $rows = '';
        for ($day = 1; $day <= 7; ++$day) {
            $dayName = $days[$day - 1];
            $daySlots = $slotsByDay[$day] ?? [];

            if ([] === $daySlots) {
                continue;
            }

            $slotList = '';
            foreach ($daySlots as $slot) {
                $teamName = $teamNames[$slot->getTeamId()] ?? 'Équipe inconnue';
                $venueName = $venueNames[$slot->getVenueId()] ?? 'Salle inconnue';
                $coachName = null !== $slot->getCoachId() ? ($coachNames[$slot->getCoachId()] ?? 'Entraîneur inconnu') : null;

                $startTime = $slot->getStartTime()->format('H:i');
                $endTime = $slot->getStartTime()->add(new \DateInterval('PT'.$slot->getDurationMinutes().'M'))->format('H:i');

                $coachInfo = null !== $coachName ? sprintf('<div class="coach">%s</div>', htmlspecialchars($coachName)) : '';

                $slotList .= sprintf(
                    '<div class="slot">
                        <div class="time">%s - %s</div>
                        <div class="team">%s</div>
                        <div class="venue">%s</div>
                        %s
                    </div>',
                    $startTime,
                    $endTime,
                    htmlspecialchars($teamName),
                    htmlspecialchars($venueName),
                    $coachInfo
                );
            }

            $rows .= sprintf(
                '<div class="day-section">
                    <h3>%s</h3>
                    <div class="slots">%s</div>
                </div>',
                $dayName,
                $slotList
            );
        }

        if ('' === $rows) {
            $rows = '<p class="empty">Aucun créneau planifié.</p>';
        }

        return sprintf(
            '<!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <style>
                    body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
                    h1 { font-size: 24px; margin-bottom: 10px; }
                    h2 { font-size: 18px; color: #666; margin-bottom: 20px; }
                    .day-section { margin-bottom: 20px; page-break-inside: avoid; }
                    .day-section h3 { font-size: 16px; background: #f0f0f0; padding: 8px; margin: 0 0 10px 0; }
                    .slot { border: 1px solid #ddd; padding: 10px; margin-bottom: 8px; page-break-inside: avoid; }
                    .time { font-weight: bold; color: #333; margin-bottom: 5px; }
                    .team { font-size: 14px; margin-bottom: 3px; }
                    .venue { font-size: 12px; color: #666; margin-bottom: 3px; }
                    .coach { font-size: 12px; color: #888; }
                    .empty { color: #999; font-style: italic; }
                </style>
            </head>
            <body>
                <h1>%s</h1>
                <h2>Planning hebdomadaire</h2>
                %s
            </body>
            </html>',
            htmlspecialchars($schedule->getName()),
            $rows
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
     * @return array<string, string>
     */
    private function getVenueNames(Schedule $schedule): array
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
    private function getCoachNames(Schedule $schedule): array
    {
        $coaches = $this->entityManager->getRepository(Coach::class)->findBy([
            'clubId' => $schedule->getClubId(),
            'seasonId' => $schedule->getSeasonId(),
        ]);

        $names = [];
        foreach ($coaches as $coach) {
            $names[$coach->getId()] = sprintf('%s %s', $coach->getFirstName(), $coach->getLastName());
        }

        return $names;
    }
}
