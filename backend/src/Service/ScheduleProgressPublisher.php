<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Schedule;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;

/**
 * Publishes schedule-generation progress on the Mercure topic
 * `club:{clubId}:schedule:{scheduleId}` (BCK-04: extracted from
 * GenerateScheduleHandler). Mercure is best-effort — the frontend polls as a
 * fallback — so publishSafely() never lets a publish failure abort or undo a
 * persisted result.
 */
final class ScheduleProgressPublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** @param array<string, mixed> $result */
    public function publish(Schedule $schedule, array $result): void
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

    /**
     * Best-effort publish: a missed event self-heals on the next poll, so a
     * publish failure is logged and swallowed, never propagated.
     *
     * @param array<string, mixed> $result
     */
    public function publishSafely(Schedule $schedule, array $result): void
    {
        try {
            $this->publish($schedule, $result);
        } catch (Throwable $exception) {
            $this->logger?->warning('Mercure publish failed (best-effort)', [
                'scheduleId' => $schedule->getId(),
                'exception' => $exception,
            ]);
        }
    }

    /**
     * Terminal-failure event for a schedule we cannot load (deleted / not found)
     * — the Schedule object is unavailable, so the topic is built from the raw ids.
     */
    public function publishTerminalFailure(string $clubId, string $scheduleId, string $error): void
    {
        $this->hub->publish(new Update(
            \sprintf('club:%s:schedule:%s', $clubId, $scheduleId),
            json_encode(['status' => 'failed', 'error' => $error], \JSON_THROW_ON_ERROR),
        ));
    }

    /** @param array<string, mixed> $result */
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
