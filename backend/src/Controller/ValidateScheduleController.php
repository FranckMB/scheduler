<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\ScheduleStatus;
use App\Repository\CalendarEntryRepository;
use App\Service\OverlayManager;
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
 * VALIDATED (read-only). To edit again, reopen it (ReopenScheduleController).
 *
 * Version model (specs/evolution/planning-versions.md): validating a SEASON
 * plan means "this version IS the plan" — it also becomes the season baseline,
 * and every sibling season-plan version is ARCHIVED (hidden safety net, purged
 * with the season, never resurrected by reopen). Overlays are never touched.
 * A sibling still generating blocks the validation (409): a running solve
 * cannot be archived out from under the worker.
 */
final class ValidateScheduleController extends AbstractController implements SeasonScopedWriteInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly \App\Service\ManagementAccessGuard $managementAccessGuard,
        private readonly CalendarEntryRepository $calendarEntryRepository,
        private readonly OverlayManager $overlayManager,
    ) {}

    #[Route('/api/schedules/{id}/validate', name: 'api_schedule_validate', methods: ['POST'])]
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

        if (ScheduleStatus::COMPLETED !== $schedule->getStatus()) {
            return $this->json(['error' => 'Only a completed schedule can be validated.'], Response::HTTP_CONFLICT);
        }

        // Version model — season plans only (an overlay validation, if it ever
        // exists, would not archive anything): collect the sibling versions.
        $siblings = [];
        if (null === $schedule->getCalendarEntryId()) {
            /** @var list<Schedule> $seasonPlans */
            $seasonPlans = $this->entityManager->getRepository(Schedule::class)->findBy([
                'clubId' => $schedule->getClubId(),
                'seasonId' => $schedule->getSeasonId(),
                'calendarEntryId' => null,
            ]);
            foreach ($seasonPlans as $sibling) {
                if ($sibling->getId() === $schedule->getId()) {
                    continue;
                }
                if (\in_array($sibling->getStatus(), [ScheduleStatus::PENDING, ScheduleStatus::GENERATING], true)) {
                    return $this->json(['error' => 'Une autre version est en cours de génération — attendez sa fin avant de valider.'], Response::HTTP_CONFLICT);
                }
                $siblings[] = $sibling;
            }
        }

        $season = $this->entityManager->getRepository(Season::class)->find($schedule->getSeasonId());

        // Destructive-edit guard (same idiom as ReopenScheduleController):
        // validating another version MOVES the baseline, which invalidates the
        // period overlays built on the old baseline's socle. Require the same
        // explicit confirmation before destroying them — never silently compose
        // overlays over a different base plan.
        $overlaysToDelete = [];
        if ($season instanceof Season
            && null === $schedule->getCalendarEntryId()
            && null !== $season->getBaselineScheduleId()
            && $schedule->getId() !== $season->getBaselineScheduleId()
        ) {
            $overlaysToDelete = $this->calendarEntryRepository->findWithOverlayByClubSeason($schedule->getClubId(), $schedule->getSeasonId());
            if ([] !== $overlaysToDelete && !$this->confirmedDeleteOverlays()) {
                return $this->json([
                    'code' => 'overlays_exist',
                    'error' => 'Validating this version replaces the baseline and deletes its overlay schedules.',
                    'count' => \count($overlaysToDelete),
                    'overlays' => array_map(static fn (CalendarEntry $e): array => [
                        'entryId' => $e->getId(),
                        'title' => $e->getTitle(),
                        'overlayScheduleId' => $e->getOverlayScheduleId(),
                    ], $overlaysToDelete),
                ], Response::HTTP_CONFLICT);
            }
        }

        // Atomic: overlay deletions + VALIDATED + baseline + socle + sibling
        // archiving commit together (a mid-loop failure must not leave a
        // half-switched plan).
        $this->entityManager->wrapInTransaction(function () use ($schedule, $season, $siblings, $overlaysToDelete): void {
            foreach ($overlaysToDelete as $entry) {
                // force: the user explicitly confirmed destroying the overlays.
                $this->overlayManager->deleteOverlayForEntry($entry, force: true);
            }

            $schedule->setStatus(ScheduleStatus::VALIDATED);

            if ($season instanceof Season && null === $schedule->getCalendarEntryId()) {
                // "This version IS the plan": the validated version becomes the
                // season baseline (validate + set-baseline used to be dissociated,
                // incoherent in the version model)…
                $season->setBaselineScheduleId($schedule->getId());
                // …sticky cockpit-unlock, stamped on first validation, never reset
                // on reopen. See accueil-cockpit-temporel.md §2ter.
                if (null === $season->getSocleValidatedAt()) {
                    $season->setSocleValidatedAt(new DateTimeImmutable);
                }
                // The non-validated sibling versions are set aside — hidden from
                // the selector, kept in DB as a safety net until the season purge.
                foreach ($siblings as $sibling) {
                    if (ScheduleStatus::VALIDATED !== $sibling->getStatus()) {
                        $sibling->setStatus(ScheduleStatus::ARCHIVED);
                    }
                }
            }
        });

        return $this->json(['id' => $schedule->getId(), 'status' => ScheduleStatus::VALIDATED->value], Response::HTTP_OK);
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
