<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class DevScheduleReportWriter
{
    private const DAYS = [
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi',
        7 => 'Dimanche',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KernelInterface $kernel,
    ) {}

    /**
     * Creates the lot directory, writes payload.json and payload-summary.txt.
     * Returns the lot directory path.
     *
     * @param array<string, mixed> $scheduleInput
     */
    public function writePayloadFiles(Schedule $schedule, array $scheduleInput): string
    {
        $base = $this->kernel->getProjectDir() . '/var/generate/schedule-' . $schedule->getId();
        $existingLots = is_dir($base) ? \count((array) glob($base . '/*', \GLOB_ONLYDIR)) : 0;
        $lotNum = str_pad((string) ($existingLots + 1), 3, '0', \STR_PAD_LEFT);
        $datetime = (new DateTimeImmutable)->format('Y_m_d-H_i');
        $lotDir = $base . '/' . $lotNum . '-' . $datetime;
        mkdir($lotDir, 0o755, true);

        // payload.json
        file_put_contents(
            $lotDir . '/payload.json',
            json_encode($scheduleInput, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        );

        // payload-summary.txt
        $constraints = $scheduleInput['constraints'] ?? [];
        $hardCount = 0;
        $preferredCount = 0;
        foreach ($constraints as $c) {
            $ruleType = $c['ruleType'] ?? $c['rule_type'] ?? '';
            if ('HARD' === $ruleType) {
                ++$hardCount;
            } elseif ('PREFERRED' === $ruleType) {
                ++$preferredCount;
            }
        }

        $summary = \sprintf(
            "Schedule : %s\nClub     : %s\nSaison   : %s\n\nÉquipes          : %d\nVenues           : %d\nContraintes      : %d  (HARD: %d, PREFERRED: %d)\nSlot templates   : %d\nCoaches          : %d\n",
            $schedule->getName(),
            $schedule->getClubId(),
            $schedule->getSeasonId(),
            \count($scheduleInput['teams'] ?? []),
            \count($scheduleInput['venues'] ?? []),
            \count($constraints),
            $hardCount,
            $preferredCount,
            \count($scheduleInput['slotTemplates'] ?? []),
            \count($scheduleInput['coaches'] ?? []),
        );

        file_put_contents($lotDir . '/payload-summary.txt', $summary);

        return $lotDir;
    }

    /**
     * Writes slots-by-team.txt, slots-by-venue.txt, diagnostics.txt (only if non-empty).
     */
    public function writeResultFiles(Schedule $schedule, string $lotDir): void
    {
        $criteria = [
            'clubId' => $schedule->getClubId(),
            'seasonId' => $schedule->getSeasonId(),
        ];

        // Load slots
        $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(
            ['scheduleId' => $schedule->getId()],
        );

        if ([] !== $slots) {
            // Build name maps
            $teamNames = [];
            foreach ($this->entityManager->getRepository(Team::class)->findBy($criteria) as $team) {
                $teamNames[$team->getId()] = $team->getName();
            }

            $venueNames = [];
            foreach ($this->entityManager->getRepository(Venue::class)->findBy($criteria) as $venue) {
                $venueNames[$venue->getId()] = $venue->getName();
            }

            $coachNames = [];
            foreach ($this->entityManager->getRepository(Coach::class)->findBy($criteria) as $coach) {
                $coachNames[$coach->getId()] = trim($coach->getFirstName() . ' ' . $coach->getLastName());
            }

            // Build team → coaches map from TeamCoach
            $teamCoaches = [];
            foreach ($this->entityManager->getRepository(TeamCoach::class)->findBy(['clubId' => $schedule->getClubId()]) as $tc) {
                $teamCoaches[$tc->getTeamId()][] = $coachNames[$tc->getCoachId()] ?? $tc->getCoachId();
            }

            // slots-by-team.txt
            $slotsByTeam = [];
            foreach ($slots as $slot) {
                $slotsByTeam[$slot->getTeamId()][] = $slot;
            }
            ksort($slotsByTeam);

            $lines = [];
            foreach ($slotsByTeam as $teamId => $teamSlots) {
                $teamName = $teamNames[$teamId] ?? $teamId;
                $coaches = $teamCoaches[$teamId] ?? [];
                $coachStr = [] !== $coaches ? implode(', ', $coaches) : '';
                $lines[] = $teamName . ('' !== $coachStr ? ' — ' . $coachStr : '');

                usort($teamSlots, static fn (ScheduleSlotTemplate $a, ScheduleSlotTemplate $b): int => $a->getDayOfWeek() <=> $b->getDayOfWeek() ?: $a->getStartTime() <=> $b->getStartTime());

                foreach ($teamSlots as $slot) {
                    $dayName = self::DAYS[$slot->getDayOfWeek()] ?? (string) $slot->getDayOfWeek();
                    $start = $slot->getStartTime()->format('H:i');
                    $end = $slot->getStartTime()->modify('+' . $slot->getDurationMinutes() . ' minutes')->format('H:i');
                    $venueName = $venueNames[$slot->getVenueId()] ?? $slot->getVenueId();
                    $lines[] = \sprintf('  %-10s %s → %s (%d min)  @ %s', $dayName, $start, $end, $slot->getDurationMinutes(), $venueName);
                }
                $lines[] = '';
            }

            file_put_contents($lotDir . '/slots-by-team.txt', implode("\n", $lines));

            // slots-by-venue.txt
            $slotsByVenue = [];
            foreach ($slots as $slot) {
                $slotsByVenue[$slot->getVenueId()][] = $slot;
            }
            ksort($slotsByVenue);

            $lines = [];
            foreach ($slotsByVenue as $venueId => $venueSlots) {
                $venueName = $venueNames[$venueId] ?? $venueId;
                $lines[] = $venueName;

                usort($venueSlots, static fn (ScheduleSlotTemplate $a, ScheduleSlotTemplate $b): int => $a->getDayOfWeek() <=> $b->getDayOfWeek() ?: $a->getStartTime() <=> $b->getStartTime());

                foreach ($venueSlots as $slot) {
                    $dayName = self::DAYS[$slot->getDayOfWeek()] ?? (string) $slot->getDayOfWeek();
                    $start = $slot->getStartTime()->format('H:i');
                    $end = $slot->getStartTime()->modify('+' . $slot->getDurationMinutes() . ' minutes')->format('H:i');
                    $teamName = $teamNames[$slot->getTeamId()] ?? $slot->getTeamId();
                    $lines[] = \sprintf('  %-10s %s → %s (%d min)   %s', $dayName, $start, $end, $slot->getDurationMinutes(), $teamName);
                }
                $lines[] = '';
            }

            file_put_contents($lotDir . '/slots-by-venue.txt', implode("\n", $lines));
        }

        // diagnostics.txt
        $diagnostics = $this->entityManager->getRepository(ScheduleDiagnostic::class)->findBy(
            ['scheduleId' => $schedule->getId()],
        );

        $statusValue = $schedule->getStatus()->value;
        $score = $schedule->getScore();
        $wallTime = $schedule->getSolverWallTimeMs();

        $header = \sprintf(
            "Statut solver : %s  |  Score : %s  |  Temps : %s ms\n",
            $statusValue,
            null !== $score ? (string) $score : 'N/A',
            null !== $wallTime ? (string) $wallTime : 'N/A',
        );

        if ([] !== $diagnostics) {
            $lines = [$header, \sprintf('Diagnostics (%d) :', \count($diagnostics))];
            foreach ($diagnostics as $d) {
                $lines[] = \sprintf('  [%-8s] %-20s — %s', strtoupper($d->getSeverity()->value), $d->getType(), $d->getMessage());
            }
            file_put_contents($lotDir . '/diagnostics.txt', implode("\n", $lines) . "\n");
        }
    }
}
