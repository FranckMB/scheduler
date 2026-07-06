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
 * Designate a finished schedule as the season's main plan (baseline). The first
 * successful schedule is auto-designated at generation time; this lets an admin
 * promote a different finished plan afterwards. Distinct from validation
 * (locking). See planning-lifecycle-validated.md.
 */
final class SetBaselineController extends AbstractController implements SeasonScopedWriteInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {}

    #[Route('/api/schedules/{id}/set-baseline', name: 'api_schedule_set_baseline', methods: ['POST'])]
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

        if (!\in_array($schedule->getStatus(), [ScheduleStatus::COMPLETED, ScheduleStatus::VALIDATED], true)) {
            return $this->json(['error' => 'Only a finished schedule can be the season main plan.'], Response::HTTP_CONFLICT);
        }

        $season = $this->entityManager->getRepository(Season::class)->find($schedule->getSeasonId());
        if (!$season instanceof Season) {
            return $this->json(['error' => 'Season not found.'], Response::HTTP_NOT_FOUND);
        }

        $season->setBaselineScheduleId($schedule->getId());

        // Sticky cockpit-unlock: covers the validate-then-baseline order — a
        // schedule already VALIDATED being designated baseline stamps the
        // milestone. Idempotent, never reset. See accueil-cockpit-temporel.md §2ter.
        if (ScheduleStatus::VALIDATED === $schedule->getStatus() && null === $season->getSocleValidatedAt()) {
            $season->setSocleValidatedAt(new DateTimeImmutable);
        }

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
