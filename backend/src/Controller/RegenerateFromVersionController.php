<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Enum\ScheduleStatus;
use App\Message\GenerateScheduleMessage;
use App\Service\ManagementAccessGuard;
use App\Service\StructureRestorer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * planning-versions D3: regenerate the season plan UNDER THE CONDITIONS of an
 * existing version — restore that version's structure photo (D2), then launch a
 * fresh generation → a new linear version (V4, V5…). The current structure is
 * replaced (the client confirms the impact first). Overlays and versions with
 * no photo (pre-D2) are refused.
 */
#[AsController]
final class RegenerateFromVersionController extends AbstractController implements SeasonScopedWriteInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly StructureRestorer $structureRestorer,
    ) {}

    #[Route('/api/schedules/{id}/regenerate-from', name: 'api_schedule_regenerate_from', methods: ['POST'])]
    public function __invoke(string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        try {
            $source = $this->entityManager->getRepository(Schedule::class)->find($id);
        } catch (Throwable) {
            $source = null;
        }

        if (!$source instanceof Schedule) {
            return $this->json(['error' => 'Schedule not found.'], Response::HTTP_NOT_FOUND);
        }

        $currentClubId = $this->resolveCurrentClubId();
        if (null !== $currentClubId && $source->getClubId() !== $currentClubId) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        // Season plans only — an overlay carries no restorable club structure.
        if (null !== $source->getCalendarEntryId()) {
            return $this->json(['error' => 'Only a season version can be regenerated from.'], Response::HTTP_CONFLICT);
        }

        // Restore the version's structure (409 inside if it has no photo), then
        // spin a brand-new version on the restored structure.
        $this->structureRestorer->restore($source);

        $newSchedule = (new Schedule)
            ->setClubId($source->getClubId())
            ->setSeasonId($source->getSeasonId())
            ->setName('Planning ' . (new DateTimeImmutable)->format('Y-m-d H:i'))
            ->setStatus(ScheduleStatus::DRAFT);
        $this->entityManager->persist($newSchedule);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new GenerateScheduleMessage(
            scheduleId: $newSchedule->getId(),
            clubId: $newSchedule->getClubId(),
        ));

        return $this->json(['id' => $newSchedule->getId(), 'status' => ScheduleStatus::DRAFT->value], Response::HTTP_ACCEPTED);
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
