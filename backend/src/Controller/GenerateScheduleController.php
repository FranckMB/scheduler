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
final class GenerateScheduleController extends AbstractController implements SeasonScopedWriteInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private RequestStack $requestStack,
        private readonly \App\Service\ManagementAccessGuard $managementAccessGuard,
        private readonly \App\Service\SocleGuard $socleGuard,
        private readonly \App\Service\GenerationComplexityGuard $complexityGuard,
    ) {}

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

        if (ScheduleStatus::VALIDATED === $schedule->getStatus()) {
            return $this->json(['error' => 'This schedule is validated (read-only). Reopen it before regenerating.'], Response::HTTP_CONFLICT);
        }

        // planning-versions: an ARCHIVED sibling is a hidden safety net — it is
        // never resurrected (a regenerate would bring back a zombie version
        // competing with the validated plan).
        if (ScheduleStatus::ARCHIVED === $schedule->getStatus()) {
            return $this->json(['error' => 'This version is archived. Generate a new version instead.'], Response::HTTP_CONFLICT);
        }

        // A secondary plan (period overlay) can only be generated once the season's
        // main plan is validated. Generating the main plan itself (no overlay) is
        // always allowed — that is how the socle gets created in the first place.
        if (null !== $schedule->getCalendarEntryId()) {
            $this->socleGuard->assertValidated($schedule->getSeasonId());
        }

        // A10: reject an over-complex problem BEFORE queuing it, so a "generation bomb"
        // never dispatches, never reaches the engine, and never holds the club's single
        // generation slot for the whole solver timeout. Status is left untouched (no
        // PENDING, no onboarding completion, no flush) on rejection.
        $violation = $this->complexityGuard->firstViolation($schedule->getClubId(), $schedule->getSeasonId());
        if (null !== $violation) {
            return $this->json([
                'error' => \sprintf(
                    'Generation blocked: too many %s (%d, limit %d). Reduce the season data before generating.',
                    $violation['cap'],
                    $violation['count'],
                    $violation['limit'],
                ),
                'cap' => $violation['cap'],
                'count' => $violation['count'],
                'limit' => $violation['limit'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
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
