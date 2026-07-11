<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\ScheduleStatus;
use App\Service\ManagementAccessGuard;
use App\Service\StructureRestorer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * planning-versions: LOAD a version's context ("Charger cette version"). Restore
 * that version's structure photo (D2) into the club's live structure and mark it
 * as the season's loaded context (★) — WITHOUT solving. The manager then works
 * from that version's plan (already COMPLETED) and hits "Régénérer" to produce a
 * new version if wanted. The current structure is replaced (the client confirms
 * the impact first). Overlays and versions with no photo (pre-D2) are refused.
 */
#[AsController]
final class RegenerateFromVersionController extends AbstractController implements SeasonScopedWriteInterface
{
    private const IN_FLIGHT = [ScheduleStatus::PENDING, ScheduleStatus::GENERATING];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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

        // Only a FINISHED, non-locked version's conditions can be replayed: a
        // VALIDATED plan is read-only (D1 model archives its siblings), an
        // ARCHIVED one is never resurrected, and a DRAFT/FAILED/in-flight one
        // has no meaningful structure to restore.
        if (ScheduleStatus::COMPLETED !== $source->getStatus()) {
            return $this->json(['error' => 'Seule une version terminée peut être régénérée (rouvrez un planning validé d\'abord).'], Response::HTTP_CONFLICT);
        }

        // The restore wipes the club structure — refuse while ANY version of the
        // season is still solving, or a concurrent import would land slots
        // referencing teams/venues the wipe just deleted (the ClubGenerationLock
        // only serialises solves, it does not guard this destructive write).
        $inFlight = $this->entityManager->getRepository(Schedule::class)->count([
            'clubId' => $source->getClubId(),
            'seasonId' => $source->getSeasonId(),
            'status' => self::IN_FLIGHT,
        ]);
        if ($inFlight > 0) {
            return $this->json(['error' => 'Une génération est en cours — attendez sa fin avant de charger une autre version.'], Response::HTTP_CONFLICT);
        }

        // Read the photo (409 if none) BEFORE any destructive change.
        $data = $this->structureRestorer->readSnapshot($source);
        $clubId = $source->getClubId();
        $seasonId = $source->getSeasonId();
        $sourceId = $source->getId();
        $sourceStatus = $source->getStatus();

        // ATOMIC: the destructive restore + re-pointing the loaded context (★)
        // commit together. No solve is launched — the source version is already
        // COMPLETED, so its plan is shown as-is; "Régénérer" produces a new
        // version later if wanted.
        $this->entityManager->wrapInTransaction(function () use ($clubId, $seasonId, $sourceId, $data): void {
            $this->structureRestorer->apply($clubId, $seasonId, $data);
            // apply() clears the identity map — reload the season AFTER it to
            // re-point the ★ (a pre-loaded instance would be detached).
            $season = $this->entityManager->getRepository(Season::class)->find($seasonId);
            if ($season instanceof Season) {
                $season->setLiveContextScheduleId($sourceId);
                $this->entityManager->flush();
            }
        });

        return $this->json(['id' => $sourceId, 'status' => $sourceStatus->value], Response::HTTP_OK);
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
