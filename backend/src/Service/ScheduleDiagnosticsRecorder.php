<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\ScheduleDiagnosticSeverity;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds and persists ScheduleDiagnostic rows from an engine result (BCK-04:
 * extracted from GenerateScheduleHandler). Only persists — the caller owns the
 * flush, keeping the generation's unit-of-work boundary intact.
 */
final class ScheduleDiagnosticsRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DiagnosticMessageBuilder $diagnosticMessageBuilder,
    ) {}

    /** Remove diagnostics from previous generation runs of this schedule. */
    public function purgePrevious(Schedule $schedule): void
    {
        $old = $this->entityManager->getRepository(ScheduleDiagnostic::class)->findBy(['scheduleId' => $schedule->getId()]);
        foreach ($old as $diagnostic) {
            $this->entityManager->remove($diagnostic);
        }
    }

    public function recordSingle(Schedule $schedule, string $type, ScheduleDiagnosticSeverity $severity, string $message): void
    {
        $this->entityManager->persist(
            (new ScheduleDiagnostic)
                ->setClubId($schedule->getClubId())
                ->setSeasonId($schedule->getSeasonId())
                ->setScheduleId($schedule->getId())
                ->setType($type)
                ->setSeverity($severity)
                ->setMessage($message)
                ->setSuggestions([]),
        );
    }

    /** @param array<string, mixed> $result */
    public function record(Schedule $schedule, array $result): void
    {
        $diagnostics = $result['diagnostics'] ?? [];
        if (!\is_array($diagnostics) || [] === $diagnostics) {
            $diagnostics = $this->buildFallbackDiagnostics($result);
        }

        if ([] === $diagnostics) {
            // No diagnostics at all. On a *failed* result, surface a generic
            // error so the manager sees something. On a *completed* result,
            // absence of diagnostics simply means "no issue detected" — a clean
            // plan must NOT carry a spurious engine_failed error.
            $engineStatus = strtolower((string) ($result['status'] ?? 'failed'));
            if ('completed' !== $engineStatus) {
                $this->recordSingle($schedule, 'engine_failed', ScheduleDiagnosticSeverity::ERROR, (string) ($result['message'] ?? 'Schedule generation failed.'));
            }

            return;
        }

        [$teamNames, $coachNames, $venueNames] = $this->buildNameMaps($schedule);

        foreach ($diagnostics as $diagnostic) {
            if (!\is_array($diagnostic)) {
                $this->recordSingle($schedule, 'engine_failed', ScheduleDiagnosticSeverity::ERROR, (string) $diagnostic);
                continue;
            }

            $message = $this->diagnosticMessageBuilder->build($diagnostic, $teamNames, $coachNames, $venueNames);

            $entity = (new ScheduleDiagnostic)
                ->setClubId($schedule->getClubId())
                ->setSeasonId($schedule->getSeasonId())
                ->setScheduleId($schedule->getId())
                ->setType((string) ($diagnostic['type'] ?? 'engine_failed'))
                ->setSeverity(ScheduleDiagnosticSeverity::tryFrom((string) ($diagnostic['severity'] ?? 'ERROR')) ?? ScheduleDiagnosticSeverity::ERROR)
                ->setMessage($message)
                ->setSuggestions(\is_array($diagnostic['suggestions'] ?? null) ? $diagnostic['suggestions'] : []);

            if (isset($diagnostic['team_id']) || isset($diagnostic['teamId'])) {
                $entity->setTeamId((string) ($diagnostic['team_id'] ?? $diagnostic['teamId']));
            }
            if (isset($diagnostic['coach_id']) || isset($diagnostic['coachId'])) {
                $entity->setCoachId((string) ($diagnostic['coach_id'] ?? $diagnostic['coachId']));
            }
            if (isset($diagnostic['venue_id']) || isset($diagnostic['venueId'])) {
                $entity->setVenueId((string) ($diagnostic['venue_id'] ?? $diagnostic['venueId']));
            }

            $this->entityManager->persist($entity);
        }
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<int, array{type: string, severity: string, teamId: string, message: string, suggestions: list<string>}>
     */
    private function buildFallbackDiagnostics(array $result): array
    {
        $unplaced = $result['unplaced'] ?? [];
        if (!\is_array($unplaced) || [] === $unplaced) {
            return [];
        }

        $diagnostics = [];
        foreach ($unplaced as $item) {
            $teamId = null;
            if (\is_array($item)) {
                $teamId = isset($item['teamId']) ? (string) $item['teamId'] : (isset($item['team_id']) ? (string) $item['team_id'] : null);
            } elseif (\is_string($item) || \is_int($item)) {
                $teamId = (string) $item;
            }

            if (null === $teamId || '' === $teamId) {
                continue;
            }

            $diagnostics[] = [
                'type' => 'unplaced',
                'severity' => 'WARNING',
                'teamId' => $teamId,
                'message' => \sprintf('Team %s could not be placed in the schedule.', $teamId),
                'suggestions' => [
                    'Add more venue availability or relax hard constraints.',
                    'Check that the team has at least one feasible time slot.',
                ],
            ];
        }

        return $diagnostics;
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, string>}
     */
    private function buildNameMaps(Schedule $schedule): array
    {
        $criteria = [
            'clubId' => $schedule->getClubId(),
            'seasonId' => $schedule->getSeasonId(),
        ];

        $teamNames = [];
        foreach ($this->entityManager->getRepository(Team::class)->findBy($criteria) as $team) {
            $teamNames[$team->getId()] = $team->getName();
        }

        $coachNames = [];
        foreach ($this->entityManager->getRepository(Coach::class)->findBy($criteria) as $coach) {
            $coachNames[$coach->getId()] = trim($coach->getFirstName() . ' ' . $coach->getLastName());
        }

        $venueNames = [];
        foreach ($this->entityManager->getRepository(Venue::class)->findBy($criteria) as $venue) {
            $venueNames[$venue->getId()] = $venue->getName();
        }

        return [$teamNames, $coachNames, $venueNames];
    }
}
