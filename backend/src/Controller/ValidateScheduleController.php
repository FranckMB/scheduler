<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\ScheduleStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Validate a COMPLETED schedule → the manager marks it finished; it becomes
 * VALIDATED (read-only). Many schedules may be validated. To edit again, reopen
 * it (see ReopenScheduleController). Designating the season's main plan is a
 * separate action (SetBaselineController). See planning-lifecycle-validated.md.
 */
final class ValidateScheduleController extends AbstractController implements SeasonScopedWriteInterface
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
            return $this->json(['error' => 'Only a completed schedule can be validated.'], Response::HTTP_CONFLICT);
        }

        $schedule->setStatus(ScheduleStatus::VALIDATED);

        // Sticky cockpit-unlock: first time the season's baseline plan is
        // validated, stamp the milestone. Idempotent, never reset on reopen.
        // See accueil-cockpit-temporel.md §2ter.
        $season = $this->entityManager->getRepository(Season::class)->find($schedule->getSeasonId());
        if ($season instanceof Season
            && $schedule->getId() === $season->getBaselineScheduleId()
            && null === $season->getSocleValidatedAt()
        ) {
            $season->setSocleValidatedAt(new DateTimeImmutable);
        }

        $this->entityManager->flush();

        return $this->json(['id' => $schedule->getId(), 'status' => ScheduleStatus::VALIDATED->value], Response::HTTP_OK);
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
