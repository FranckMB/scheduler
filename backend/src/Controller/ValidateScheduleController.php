<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\ScheduleStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Re-designate a COMPLETED schedule as the season's baseline (the "main" plan).
 * The first successful schedule is auto-designated at generation time; this lets
 * an admin promote a better alternative afterwards.
 */
final class ValidateScheduleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {}

    #[Route('/api/schedules/{id}/validate', name: 'api_schedule_validate', methods: ['POST'])]
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

        if (ScheduleStatus::COMPLETED !== $schedule->getStatus()) {
            return $this->json(['error' => 'Only a completed schedule can become the baseline.'], Response::HTTP_CONFLICT);
        }

        $season = $this->entityManager->getRepository(Season::class)->find($schedule->getSeasonId());
        if (!$season instanceof Season) {
            return $this->json(['error' => 'Season not found.'], Response::HTTP_NOT_FOUND);
        }

        $season->setBaselineScheduleId($schedule->getId());
        $this->entityManager->flush();

        return $this->json(['baselineScheduleId' => $schedule->getId()], Response::HTTP_OK);
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
