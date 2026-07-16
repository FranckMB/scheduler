<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Schedule;
use App\Entity\Season;
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
 * Reopen the version a plan points at → the plan un-points it and becomes an
 * "espace de travail" again, editable (ADR-0002 inv. 2). The inverse of
 * ValidateScheduleController. Reopening the season plan's chosen version while
 * period overlays exist destroys them (inv. 14) — guarded by a 409 the client
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
        private readonly \App\Service\SchedulePlanProvisioner $schedulePlanProvisioner,
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

        // ADR-0002 inv. 1/2 : « validé » = le plan pointe cette version. Rouvrir =
        // dépointer. Une version que le plan ne pointe pas n'a rien à rouvrir.
        if (!$this->schedulePlanProvisioner->isChosen($schedule->getId())) {
            return $this->json(['error' => 'Seule la version choisie d\'un planning peut être rouverte.'], Response::HTTP_CONFLICT);
        }

        // Garde destructive : dépointer le calendrier de base invalide les plans
        // secondaires bâtis dessus (inv. 14). Clé sur le pointeur, comme le release
        // qu'elle protège — les clé sur deux vérités différentes laissait un reopen
        // orphaniser des plans secondaires vivants sans jamais demander confirmation.
        if ($schedule->getId() === $this->schedulePlanProvisioner->chosenOfSeasonPlan($schedule->getSeasonId())) {
            $overlays = $this->calendarEntryRepository->findWithOverlayByClubSeason($schedule->getClubId(), $schedule->getSeasonId());
            if ([] !== $overlays) {
                if (!$this->confirmedDeleteOverlays()) {
                    return $this->json([
                        'code' => 'overlays_exist',
                        'error' => 'Rouvrir le planning de la saison supprime ses plannings secondaires.',
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
                    // inv. 2 : rouvrir dépointe — le plan redevient un espace de travail.
                    $this->schedulePlanProvisioner->releaseSchedule($schedule->getId());
                });

                return $this->json(['id' => $schedule->getId(), 'chosen' => false], Response::HTTP_OK);
            }
        }

        // inv. 2 : rouvrir dépointe — le plan redevient un espace de travail.
        $this->schedulePlanProvisioner->releaseSchedule($schedule->getId());

        return $this->json(['id' => $schedule->getId(), 'chosen' => false], Response::HTTP_OK);
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
