<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\ScheduleStatus;
use App\Repository\CalendarEntryRepository;
use App\Service\OverlayManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Reopen a VALIDATED schedule → back to COMPLETED (editable again). The inverse
 * of ValidateScheduleController. Reopening the season BASELINE while period
 * overlays exist destroys them (spec §2bis) — guarded by a 409 that the client
 * confirms with {"confirmDeleteOverlays": true}. See planning-lifecycle-validated.md.
 */
final class ReopenScheduleController extends AbstractController implements SeasonScopedWriteInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly CalendarEntryRepository $calendarEntryRepository,
        private readonly OverlayManager $overlayManager,
        private readonly \App\Service\ManagementAccessGuard $managementAccessGuard,
    ) {}

    #[Route('/api/schedules/{id}/reopen', name: 'api_schedule_reopen', methods: ['POST'])]
    public function __invoke(string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

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

        // Destructive-edit guard: reopening the baseline invalidates its overlays.
        $season = $this->entityManager->getRepository(Season::class)->find($schedule->getSeasonId());
        if ($season instanceof Season && $schedule->getId() === $season->getBaselineScheduleId()) {
            $overlays = $this->calendarEntryRepository->findWithOverlayByClubSeason($schedule->getClubId(), $schedule->getSeasonId());
            if ([] !== $overlays) {
                if (!$this->confirmedDeleteOverlays()) {
                    return $this->json([
                        'code' => 'overlays_exist',
                        'error' => 'Reopening the baseline deletes its overlay schedules.',
                        'count' => \count($overlays),
                        'overlays' => array_map(static fn (CalendarEntry $e): array => [
                            'entryId' => $e->getId(),
                            'title' => $e->getTitle(),
                            'overlayScheduleId' => $e->getOverlayScheduleId(),
                        ], $overlays),
                    ], Response::HTTP_CONFLICT);
                }
                // Atomic: all overlay deletions + the reopen commit together (a
                // mid-loop failure must not leave a half-deleted, still-locked state).
                $this->entityManager->wrapInTransaction(function () use ($overlays, $schedule): void {
                    foreach ($overlays as $entry) {
                        // force: the user explicitly confirmed destroying the overlays,
                        // VALIDATED ones included (this IS the authorized destructive path).
                        $this->overlayManager->deleteOverlayForEntry($entry, force: true);
                    }
                    $schedule->setStatus(ScheduleStatus::COMPLETED);
                });

                return $this->json(['id' => $schedule->getId(), 'status' => ScheduleStatus::COMPLETED->value], Response::HTTP_OK);
            }
        }

        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $this->entityManager->flush();

        return $this->json(['id' => $schedule->getId(), 'status' => ScheduleStatus::COMPLETED->value], Response::HTTP_OK);
    }

    private function confirmedDeleteOverlays(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        $content = $request?->getContent();
        if (!\is_string($content) || '' === $content) {
            return false;
        }
        $data = json_decode($content, true);

        return \is_array($data) && true === ($data['confirmDeleteOverlays'] ?? false);
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
