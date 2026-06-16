<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Coach;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\ScheduleStatus;
use App\Message\GenerateScheduleMessage;
use App\Service\ClubGenerationLock;
use App\Service\DevScheduleReportWriter;
use App\Service\DiagnosticMessageBuilder;
use App\Service\ScheduleConstraintBuilder;
use App\Service\ScheduleResultImporter;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

#[AsMessageHandler]
final class GenerateScheduleHandler
{
    private const ENGINE_URL = 'http://engine:8000/generate';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ScheduleConstraintBuilder $constraintBuilder,
        private ScheduleResultImporter $resultImporter,
        private HttpClientInterface $httpClient,
        private HubInterface $hub,
        private ClubGenerationLock $clubGenerationLock,
        private DiagnosticMessageBuilder $diagnosticMessageBuilder,
        private ?DevScheduleReportWriter $devReportWriter = null,
    ) {}

    public function __invoke(GenerateScheduleMessage $message): void
    {
        $schedule = $this->findSchedule($message->getScheduleId());
        if (!$schedule instanceof Schedule) {
            return;
        }

        if ($schedule->getClubId() !== $message->getClubId()) {
            $this->failSchedule($schedule, 'club_mismatch', 'Message club_id does not match schedule club_id.');
            $this->publishProgress($schedule, []);
            $this->entityManager->flush();

            return;
        }

        $lockToken = $this->clubGenerationLock->acquire($message->getClubId(), $message->getTimeoutSeconds() + 60);
        if (null === $lockToken) {
            $schedule->setStatus(ScheduleStatus::PENDING);
            $this->entityManager->flush();

            throw new RecoverableMessageHandlingException(\sprintf('Schedule generation already running for club %s.', $message->getClubId()));
        }

        try {
            $this->generate($schedule, $message);
        } finally {
            $this->clubGenerationLock->release($message->getClubId(), $lockToken);
        }
    }

    private function generate(Schedule $schedule, GenerateScheduleMessage $message): void
    {
        $scheduleInput = $this->buildFrozenSnapshot($schedule);
        $schedule
            ->setStatus(ScheduleStatus::GENERATING)
            ->setSolverTimeoutSeconds($message->getTimeoutSeconds())
            ->setSnapshotData($scheduleInput)
            ->setSnapshotHash($this->hashSnapshot($scheduleInput));

        $this->entityManager->flush();

        $lotDir = null;
        if (null !== $this->devReportWriter) {
            try {
                $lotDir = $this->devReportWriter->writePayloadFiles($schedule, $scheduleInput);
            } catch (Throwable) {
                // never crash the generation over a report write failure
            }
        }

        try {
            $response = $this->httpClient->request('POST', self::ENGINE_URL, [
                'json' => $scheduleInput,
                'timeout' => $message->getTimeoutSeconds(),
            ]);

            $result = $response->toArray(false);
        } catch (TransportExceptionInterface) {
            $schedule->setStatus(ScheduleStatus::FAILED);
            $this->persistDiagnostic($schedule, 'engine_timeout', ScheduleDiagnosticSeverity::ERROR, 'Schedule generation timed out.');
            $this->publishProgress($schedule, ['warnings' => ['Schedule generation timed out.']]);
            $this->entityManager->flush();

            return;
        } catch (Throwable $exception) {
            $this->failSchedule($schedule, 'engine_error', $exception->getMessage());
            $this->publishProgress($schedule, ['warnings' => [$exception->getMessage()]]);
            $this->entityManager->flush();

            return;
        }

        $this->applyEngineResult($schedule, $result);
        $this->publishProgress($schedule, $result);
        $this->entityManager->flush();

        if (null !== $this->devReportWriter && null !== $lotDir) {
            try {
                $this->devReportWriter->writeResultFiles($schedule, $lotDir);
            } catch (Throwable) {
                // never crash the generation over a report write failure
            }
        }
    }

    private function findSchedule(string $scheduleId): ?Schedule
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($scheduleId);

        return $schedule instanceof Schedule ? $schedule : null;
    }

    /** @return array<string, mixed> */
    private function buildFrozenSnapshot(Schedule $schedule): array
    {
        return $this->constraintBuilder->buildForClubSeason(
            $schedule->getClubId(),
            $schedule->getSeasonId(),
        );
    }

    /** @param array<string, mixed> $result */
    private function applyEngineResult(Schedule $schedule, array $result): void
    {
        $engineStatus = strtolower((string) ($result['status'] ?? 'failed'));
        $metrics = $result['metrics'] ?? $result['solver_metrics'] ?? null;
        if (\is_array($metrics)) {
            $this->applyScoreAndMetrics($schedule, $result);
        } elseif (isset($result['score']) && is_numeric($result['score'])) {
            $schedule->setScore((int) $result['score']);
        }

        if (\in_array($engineStatus, ['failed', 'infeasible'], true)) {
            $schedule->setStatus(ScheduleStatus::FAILED);
            $this->persistDiagnostics($schedule, $result);

            return;
        }

        if ('completed' !== $engineStatus) {
            $schedule->setStatus(ScheduleStatus::FAILED);
            $this->persistDiagnostic($schedule, 'engine_status', ScheduleDiagnosticSeverity::ERROR, \sprintf('Unsupported engine status "%s".', $engineStatus));

            return;
        }

        $this->resultImporter->import($schedule, $result);
        $schedule->setStatus(ScheduleStatus::COMPLETED);
    }

    /** @param array<string, mixed> $result */
    private function applyScoreAndMetrics(Schedule $schedule, array $result): void
    {
        if (isset($result['score']) && is_numeric($result['score'])) {
            $schedule->setScore((int) $result['score']);
        }

        $metrics = $result['metrics'] ?? $result['solver_metrics'] ?? [];
        if (!\is_array($metrics)) {
            return;
        }

        if (isset($metrics['solver_version']) || isset($metrics['solverVersion'])) {
            $schedule->setSolverVersion((string) ($metrics['solver_version'] ?? $metrics['solverVersion']));
        }
        if (isset($metrics['constraint_version']) || isset($metrics['constraintVersion'])) {
            $schedule->setConstraintVersion((string) ($metrics['constraint_version'] ?? $metrics['constraintVersion']));
        }
        if (isset($metrics['score_formula_version']) || isset($metrics['scoreFormulaVersion'])) {
            $schedule->setScoreFormulaVersion((string) ($metrics['score_formula_version'] ?? $metrics['scoreFormulaVersion']));
        }
        if ((isset($metrics['nb_variables']) && is_numeric($metrics['nb_variables'])) || (isset($metrics['nbVariables']) && is_numeric($metrics['nbVariables']))) {
            $schedule->setSolverNbVariables((int) ($metrics['nb_variables'] ?? $metrics['nbVariables']));
        }
        if ((isset($metrics['nb_constraints']) && is_numeric($metrics['nb_constraints'])) || (isset($metrics['nbConstraints']) && is_numeric($metrics['nbConstraints']))) {
            $schedule->setSolverNbConstraints((int) ($metrics['nb_constraints'] ?? $metrics['nbConstraints']));
        }
        if ((isset($metrics['nb_conflicts']) && is_numeric($metrics['nb_conflicts'])) || (isset($metrics['nbConflicts']) && is_numeric($metrics['nbConflicts']))) {
            $schedule->setSolverNbConflicts((int) ($metrics['nb_conflicts'] ?? $metrics['nbConflicts']));
        }
        if ((isset($metrics['wall_time_ms']) && is_numeric($metrics['wall_time_ms'])) || (isset($metrics['wallTimeMs']) && is_numeric($metrics['wallTimeMs']))) {
            $schedule->setSolverWallTimeMs((int) ($metrics['wall_time_ms'] ?? $metrics['wallTimeMs']));
        }
    }

    private function failSchedule(Schedule $schedule, string $type, string $message): void
    {
        $schedule->setStatus(ScheduleStatus::FAILED);
        $this->persistDiagnostic($schedule, $type, ScheduleDiagnosticSeverity::ERROR, $message);
    }

    /** @param array<string, mixed> $result */
    private function persistDiagnostics(Schedule $schedule, array $result): void
    {
        $diagnostics = $result['diagnostics'] ?? [];
        if (!\is_array($diagnostics) || [] === $diagnostics) {
            $diagnostics = $this->buildFallbackDiagnostics($result);
            if ([] === $diagnostics) {
                $this->persistDiagnostic($schedule, 'engine_failed', ScheduleDiagnosticSeverity::ERROR, (string) ($result['message'] ?? 'Schedule generation failed.'));

                return;
            }
        }

        [$teamNames, $coachNames, $venueNames] = $this->buildNameMaps($schedule);

        foreach ($diagnostics as $diagnostic) {
            if (!\is_array($diagnostic)) {
                $this->persistDiagnostic($schedule, 'engine_failed', ScheduleDiagnosticSeverity::ERROR, (string) $diagnostic);
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

    private function persistDiagnostic(Schedule $schedule, string $type, ScheduleDiagnosticSeverity $severity, string $message): void
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
    private function publishProgress(Schedule $schedule, array $result): void
    {
        $topic = \sprintf('club:%s:schedule:%s', $schedule->getClubId(), $schedule->getId());
        if ('club::schedule:' === $topic) {
            throw new LogicException('Schedule Mercure topic cannot be empty.');
        }

        $this->hub->publish(new Update($topic, json_encode([
            'status' => $schedule->getStatus(),
            'score' => $schedule->getScore(),
            'unplaced' => $this->countUnplacedTeams($result),
            'warnings' => array_values(\is_array($result['warnings'] ?? null) ? $result['warnings'] : []),
        ], \JSON_THROW_ON_ERROR)));
    }

    /** @param array<string, mixed> $snapshot */
    private function hashSnapshot(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $result
     */
    private function countUnplacedTeams(array $result): int
    {
        $unplaced = $result['unplaced'] ?? null;
        if (\is_array($unplaced)) {
            return \count($unplaced);
        }

        $diagnostics = $result['diagnostics'] ?? null;
        if (!\is_array($diagnostics)) {
            return 0;
        }

        $count = 0;
        foreach ($diagnostics as $diagnostic) {
            if (\is_array($diagnostic) && 'unplaced' === ($diagnostic['type'] ?? null)) {
                ++$count;
            }
        }

        return $count;
    }
}
