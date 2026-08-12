<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
use App\Exception\ScheduleGenerationInProgressException;
use App\Service\ManagementAccessGuard;
use App\Service\ManualEditService;
use App\Service\MoveSlotService;
use App\Service\SchedulePlanProvisioner;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

final class ManualEditController extends AbstractController implements SeasonScopedWriteInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ManualEditService $manualEditService,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly LoggerInterface $logger,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly MoveSlotService $moveSlotService,
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

    /**
     * Déplacer un créneau (jour / heure / gymnase) SOUS LE VERDICT DU MOTEUR (F2b).
     *
     * Seul rail de déplacement : le geste ne s'écrit QUE si le moteur l'accepte ;
     * sinon les règles violées reviennent nommées et le planning ne bouge pas.
     */
    #[Route('/api/schedule-slots/{id}/move', name: 'api_schedule_slot_move', methods: ['POST'])]
    public function move(string $id, Request $request): JsonResponse
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

        $dayOfWeek = isset($data['dayOfWeek']) ? (int) $data['dayOfWeek'] : 0;
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            return $this->json(['error' => 'Missing or invalid field: dayOfWeek.'], Response::HTTP_BAD_REQUEST);
        }

        $venueId = isset($data['venueId']) && \is_string($data['venueId']) ? $data['venueId'] : '';
        if ('' === $venueId) {
            return $this->json(['error' => 'Missing required field: venueId.'], Response::HTTP_BAD_REQUEST);
        }

        $startTime = null;
        if (isset($data['startTime']) && \is_string($data['startTime'])) {
            $startTime = DateTimeImmutable::createFromFormat('!H:i', $data['startTime'])
                ?: DateTimeImmutable::createFromFormat('!H:i:s', $data['startTime'])
                ?: null;
        }
        if (!$startTime instanceof DateTimeImmutable) {
            return $this->json(['error' => 'Missing or invalid field: startTime.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->moveSlotService->move($slot, $dayOfWeek, $startTime, $venueId);
        } catch (ScheduleGenerationInProgressException) {
            // Déplacer pendant qu'une génération réécrit le planning écraserait son résultat.
            return $this->json(['code' => 'generation_in_progress'], Response::HTTP_CONFLICT);
        } catch (TransportExceptionInterface $e) {
            // Le moteur n'a pas répondu — RIEN n'est écrit, le gestionnaire réessaie.
            $this->logger->error('Move validation could not reach the engine.', ['exception' => $e]);

            return $this->json(['error' => 'The engine did not respond — please retry.'], Response::HTTP_BAD_GATEWAY);
        } catch (Throwable $e) {
            // SEC-08 : on journalise le détail, jamais getMessage() au client.
            $this->logger->error('Slot move failed.', ['exception' => $e]);

            return $this->json(['error' => 'The request could not be processed.'], Response::HTTP_BAD_REQUEST);
        }

        if (false === $result['valid']) {
            // Le moteur refuse : 422 + les règles violées, nommées pour l'UI.
            return $this->json(['valid' => false, 'violations' => $result['violations']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['message' => 'Slot moved.', 'valid' => true], Response::HTTP_OK);
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

        // ADR-0002 inv. 1 : « verrouillé » = c'est la version choisie du plan.
        return $schedule instanceof Schedule && $this->schedulePlanProvisioner->isChosen($schedule->getId());
    }
}
