<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Service\ManualEditService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class ManualEditController extends AbstractController implements SeasonScopedWriteInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ManualEditService $manualEditService,
        private readonly \App\Service\ManagementAccessGuard $managementAccessGuard,
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {}

    #[Route('/api/schedule-slots/{id}/manual-edit/constraint', name: 'api_manual_edit_constraint', methods: ['POST'])]
    public function applyConstraint(string $id, Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07
        $slot = $this->findSlot($id);

        if (!$slot instanceof ScheduleSlotTemplate) {
            return $this->json(['error' => 'Slot not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($this->scheduleIsLocked($slot)) {
            return $this->json(['error' => 'This schedule is validated (read-only). Reopen it before editing.'], Response::HTTP_CONFLICT);
        }

        $data = json_decode($request->getContent(), true);

        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        $type = (string) ($data['type'] ?? '');

        if ('' === $type) {
            return $this->json(['error' => 'Missing required field: type.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $constraint = $this->manualEditService->applyPermanentConstraint(
                $slot,
                $type,
                isset($data['reason']) ? (string) $data['reason'] : null,
                isset($data['createdBy']) ? (string) $data['createdBy'] : null,
            );
        } catch (Throwable $e) {
            // SEC-08: log the internal detail, never surface getMessage() to the client.
            $this->logger->error('Manual edit failed.', ['exception' => $e]);

            return $this->json(['error' => 'The request could not be processed.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'message' => 'Permanent constraint created.',
            'constraintId' => $constraint->getId(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/schedule-slots/{id}/manual-edit/lock', name: 'api_manual_edit_lock', methods: ['POST'])]
    public function applyLock(string $id, Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07
        $slot = $this->findSlot($id);

        if (!$slot instanceof ScheduleSlotTemplate) {
            return $this->json(['error' => 'Slot not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($this->scheduleIsLocked($slot)) {
            return $this->json(['error' => 'This schedule is validated (read-only). Reopen it before editing.'], Response::HTTP_CONFLICT);
        }

        $data = json_decode($request->getContent(), true);

        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        $lockLevelValue = (string) ($data['lockLevel'] ?? '');

        if ('' === $lockLevelValue) {
            return $this->json(['error' => 'Missing required field: lockLevel.'], Response::HTTP_BAD_REQUEST);
        }

        $lockLevel = LockLevel::tryFrom($lockLevelValue);

        if (null === $lockLevel) {
            return $this->json(['error' => 'Invalid lockLevel.'], Response::HTTP_BAD_REQUEST);
        }

        // ENG-21: SOFT is a placebo — the engine never reads the soft-lock penalty, so a
        // SOFT lock has zero effect on placement. Reject it rather than accept a lock we
        // silently ignore ("déclaré ≠ effectif"). Only NONE/HARD are honored.
        if (LockLevel::SOFT === $lockLevel) {
            return $this->json(['error' => 'SOFT lock is not supported (no solver effect); use NONE or HARD.'], Response::HTTP_BAD_REQUEST);
        }

        $this->manualEditService->applyLock($slot, $lockLevel);

        return $this->json(['message' => 'Lock applied.'], Response::HTTP_OK);
    }

    #[Route('/api/schedule-slots/{id}/manual-edit/one-time', name: 'api_manual_edit_one_time', methods: ['POST'])]
    public function applyOneTimeUpdate(string $id, Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07
        $slot = $this->findSlot($id);

        if (!$slot instanceof ScheduleSlotTemplate) {
            return $this->json(['error' => 'Slot not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($this->scheduleIsLocked($slot)) {
            return $this->json(['error' => 'This schedule is validated (read-only). Reopen it before editing.'], Response::HTTP_CONFLICT);
        }

        $data = json_decode($request->getContent(), true);

        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['startTime']) && \is_string($data['startTime'])) {
            $time = DateTimeImmutable::createFromFormat('!H:i', $data['startTime'])
                ?: DateTimeImmutable::createFromFormat('!H:i:s', $data['startTime']);

            if ($time instanceof DateTimeImmutable) {
                $data['startTime'] = $time;
            }
        }

        try {
            $this->manualEditService->applyOneTimeUpdate($slot, $data);
        } catch (InvalidArgumentException $e) {
            // Doctrine's ORMInvalidArgumentException extends InvalidArgumentException:
            // only the service's own domain messages may reach the client (SEC-08).
            if ($e instanceof \Doctrine\ORM\ORMInvalidArgumentException) {
                $this->logger->error('Manual edit failed.', ['exception' => $e]);

                return $this->json(['error' => 'The request could not be processed.'], Response::HTTP_BAD_REQUEST);
            }

            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (Throwable $e) {
            // SEC-08: log the internal detail, never surface getMessage() to the client.
            $this->logger->error('Manual edit failed.', ['exception' => $e]);

            return $this->json(['error' => 'The request could not be processed.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['message' => 'One-time update applied.'], Response::HTTP_OK);
    }

    private function findSlot(string $id): ?ScheduleSlotTemplate
    {
        try {
            $slot = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->find($id);
        } catch (Throwable) {
            $slot = null;
        }

        return $slot instanceof ScheduleSlotTemplate ? $slot : null;
    }

    /** A slot whose parent schedule is VALIDATED is read-only. */
    private function scheduleIsLocked(ScheduleSlotTemplate $slot): bool
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($slot->getScheduleId());

        return $schedule instanceof Schedule && ScheduleStatus::VALIDATED === $schedule->getStatus();
    }
}
