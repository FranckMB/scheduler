<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Enum\ScheduleStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Reopen a VALIDATED schedule → back to COMPLETED (editable again). The inverse
 * of ValidateScheduleController. See planning-lifecycle-validated.md.
 */
final class ReopenScheduleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {}

    #[Route('/api/schedules/{id}/reopen', name: 'api_schedule_reopen', methods: ['POST'])]
    public function __invoke(string $id): JsonResponse
    {
        try {
            $schedule = $this->entityManager->getRepository(Schedule::class)->find($id);
        } catch (Throwable) {
            $schedule = null;
        }

        if (!$schedule instanceof Schedule) {
            return $this->json(['error' => 'Schedule not found.'], Response::HTTP_NOT_FOUND);
        }

        $currentClubId = $this->resolveCurrentClubId();
        if (null !== $currentClubId && $schedule->getClubId() !== $currentClubId) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        if (ScheduleStatus::VALIDATED !== $schedule->getStatus()) {
            return $this->json(['error' => 'Only a validated schedule can be reopened.'], Response::HTTP_CONFLICT);
        }

        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $this->entityManager->flush();

        return $this->json(['id' => $schedule->getId(), 'status' => ScheduleStatus::COMPLETED->value], Response::HTTP_OK);
    }

    private function resolveCurrentClubId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        $clubId = $request?->attributes->get('_club_id');
        if (\is_string($clubId) && '' !== $clubId) {
            return $clubId;
        }

        $clubId = $request?->headers->get('X-Club-Id');
        if (\is_string($clubId) && '' !== $clubId) {
            return $clubId;
        }

        return null;
    }
}
