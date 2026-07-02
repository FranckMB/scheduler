<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Enum\ScheduleStatus;
use App\Message\GenerateScheduleMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

#[AsController]
final class GenerateScheduleController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private RequestStack $requestStack,
    ) {}

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

        if (ScheduleStatus::VALIDATED === $schedule->getStatus()) {
            return $this->json(['error' => 'This schedule is validated (read-only). Reopen it before regenerating.'], Response::HTTP_CONFLICT);
        }

        $schedule->setStatus(ScheduleStatus::PENDING);

        // Launching the first generation completes onboarding (the wizard is done);
        // done at queue time so the UI can leave /wizard for the work loop right away,
        // regardless of whether the solve ends up feasible.
        $club = $this->entityManager->getRepository(Club::class)->find($schedule->getClubId());
        if ($club instanceof Club && !$club->getOnboardingCompleted()) {
            $club->setOnboardingCompleted(true);
        }

        $this->entityManager->flush();

        $this->messageBus->dispatch(
            new GenerateScheduleMessage(
                scheduleId: $schedule->getId(),
                clubId: $schedule->getClubId(),
            ),
        );

        return $this->json(['message' => 'Schedule generation queued'], Response::HTTP_ACCEPTED);
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
