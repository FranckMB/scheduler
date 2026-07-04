<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\ScheduleStatus;
use App\Message\GenerateScheduleMessage;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Throwable;

/**
 * BCK-01: when a GenerateScheduleMessage fails *permanently* (all retries
 * exhausted → routed to the failure transport), the schedule row would otherwise
 * stay non-terminal forever. The main handler acks the failures it records
 * itself; what reaches here is the residue it cannot — chiefly lock-exhaustion
 * (RecoverableMessageHandlingException rethrown until retries run out) and any
 * throw escaping __invoke. This flips the row to FAILED and notifies the client.
 *
 * Precise by construction: it acts only on willRetry() === false, so a message
 * that still has retries left (e.g. contention that later clears) is untouched.
 */
#[AsEventListener(event: WorkerMessageFailedEvent::class)]
final readonly class ScheduleGenerationFailureListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantConnectionContext $tenantConnectionContext,
        private HubInterface $hub,
        private ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof GenerateScheduleMessage) {
            return;
        }

        // Worker context: no GUC is set on this failure path → scope to the
        // message's club so RLS lets us read and update the schedule, then clear.
        $this->tenantConnectionContext->setClubId($message->getClubId());

        try {
            $schedule = $this->entityManager->getRepository(Schedule::class)->find($message->getScheduleId());
            if (!$schedule instanceof Schedule
                || !\in_array($schedule->getStatus(), [ScheduleStatus::PENDING, ScheduleStatus::GENERATING], true)) {
                return;
            }

            $schedule->setStatus(ScheduleStatus::FAILED);
            $this->entityManager->persist(
                (new ScheduleDiagnostic)
                    ->setClubId($schedule->getClubId())
                    ->setSeasonId($schedule->getSeasonId())
                    ->setScheduleId($schedule->getId())
                    ->setType('generation_failed')
                    ->setSeverity(ScheduleDiagnosticSeverity::ERROR)
                    ->setMessage('Schedule generation could not be completed. Please regenerate.')
                    ->setSuggestions([]),
            );
            $this->entityManager->flush();

            $this->hub->publish(new Update(
                \sprintf('club:%s:schedule:%s', $message->getClubId(), $message->getScheduleId()),
                json_encode(['status' => 'failed', 'error' => 'generation_failed'], \JSON_THROW_ON_ERROR),
            ));
        } catch (Throwable $exception) {
            // The unit of work may already be broken (the original failure closed
            // it): the stuck-schedule watchdog reconciles the row on its next pass.
            $this->logger?->error('Failed to finalize a permanently-failed schedule', [
                'scheduleId' => $message->getScheduleId(),
                'exception' => $exception,
            ]);
        } finally {
            $this->tenantConnectionContext->clear();
        }
    }
}
