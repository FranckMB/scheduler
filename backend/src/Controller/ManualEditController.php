<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
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

final class ManualEditController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ManualEditService $manualEditService,
    ) {}

    #[Route('/api/schedule-slots/{id}/manual-edit/constraint', name: 'api_manual_edit_constraint', methods: ['POST'])]
    public function applyConstraint(string $id, Request $request): JsonResponse
    {
        $slot = $this->findSlot($id);

        if (!$slot instanceof ScheduleSlotTemplate) {
            return $this->json(['error' => 'Slot not found.'], Response::HTTP_NOT_FOUND);
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
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'message' => 'Permanent constraint created.',
            'constraintId' => $constraint->getId(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/schedule-slots/{id}/manual-edit/lock', name: 'api_manual_edit_lock', methods: ['POST'])]
    public function applyLock(string $id, Request $request): JsonResponse
    {
        $slot = $this->findSlot($id);

        if (!$slot instanceof ScheduleSlotTemplate) {
            return $this->json(['error' => 'Slot not found.'], Response::HTTP_NOT_FOUND);
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

        $this->manualEditService->applyLock($slot, $lockLevel);

        return $this->json(['message' => 'Lock applied.'], Response::HTTP_OK);
    }

    #[Route('/api/schedule-slots/{id}/manual-edit/one-time', name: 'api_manual_edit_one_time', methods: ['POST'])]
    public function applyOneTimeUpdate(string $id, Request $request): JsonResponse
    {
        $slot = $this->findSlot($id);

        if (!$slot instanceof ScheduleSlotTemplate) {
            return $this->json(['error' => 'Slot not found.'], Response::HTTP_NOT_FOUND);
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
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
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
}
