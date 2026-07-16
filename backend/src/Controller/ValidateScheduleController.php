<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\ScheduleStatus;
use App\Repository\CalendarEntryRepository;
use App\Service\OverlayManager;
use App\Service\SchedulePlanProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Choose a COMPLETED version: the manager settles on it and the plan POINTS at
 * it (ADR-0002 inv. 1). To edit again, reopen it (ReopenScheduleController).
 *
 * "Validated" is not a status — it is derived from the pointer, which is the
 * single truth. Choosing a version also DELETES its siblings of the same scope
 * (season versions share calendarEntryId=null, a period's versions share that
 * period's id): the plan holds the one version that counts, not a graveyard.
 * Overlays are never touched by a season-plan choice. A sibling still
 * generating blocks the choice (409) — a running solve cannot be deleted out
 * from under the worker.
 */
final class ValidateScheduleController extends AbstractController implements SeasonScopedWriteInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly \App\Service\ManagementAccessGuard $managementAccessGuard,
        private readonly CalendarEntryRepository $calendarEntryRepository,
        private readonly OverlayManager $overlayManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
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

        // Version model: validating a version archives its SIBLINGS of the same
        // scope — season plans share calendarEntryId=null, a period's overlay
        // versions share that period's id. Collect them (refuse while one solves).
        $entryId = $schedule->getCalendarEntryId();
        /** @var list<Schedule> $versions */
        $versions = $this->entityManager->getRepository(Schedule::class)->findBy([
            'clubId' => $schedule->getClubId(),
            'seasonId' => $schedule->getSeasonId(),
            'calendarEntryId' => $entryId,
        ]);
        $siblings = [];
        foreach ($versions as $sibling) {
            if ($sibling->getId() === $schedule->getId()) {
                continue;
            }
            if (\in_array($sibling->getStatus(), [ScheduleStatus::PENDING, ScheduleStatus::GENERATING], true)) {
                return $this->json(['error' => 'Une autre version est en cours de génération — attendez sa fin avant de valider.'], Response::HTTP_CONFLICT);
            }
            $siblings[] = $sibling;
        }
        $overlayEntry = null !== $entryId
            ? $this->entityManager->getRepository(CalendarEntry::class)->find($entryId)
            : null;

        $season = $this->entityManager->getRepository(Season::class)->find($schedule->getSeasonId());

        // Garde destructive (même idiome que ReopenScheduleController) : choisir une
        // AUTRE version déplace le calendrier de base, ce qui invalide les plans
        // secondaires bâtis sur l'ancien socle. Clé sur le POINTEUR — la seule
        // vérité (inv. 1/14) — et jamais composer silencieusement un ajustement
        // par-dessus un autre plan de base.
        // Un pointeur NULL n'exempte pas : le plan est alors un espace de travail, mais
        // des plans secondaires peuvent survivre (socle rouvert, donnée migrée). Choisir
        // cette version leur donnerait un autre socle que celui sur lequel ils ont été
        // bâtis — silencieusement. La seule question est « le plan pointe-t-il DÉJÀ cette
        // version ? » ; sinon le calendrier bouge, et il faut le confirmer.
        // (Cas normal : à la 1re validation aucun plan secondaire n'existe — inv. 13 les
        // interdit sans socle pointé — donc la garde ne coûte rien.)
        $currentlyChosen = $this->schedulePlanProvisioner->chosenOfSeasonPlan($schedule->getSeasonId());
        $overlaysToDelete = [];
        if (null === $schedule->getCalendarEntryId()
            && $schedule->getId() !== $currentlyChosen
        ) {
            $overlaysToDelete = $this->calendarEntryRepository->findWithOverlayByClubSeason($schedule->getClubId(), $schedule->getSeasonId());
            if ([] !== $overlaysToDelete && !$this->confirmedDeleteOverlays()) {
                return $this->json([
                    'code' => 'overlays_exist',
                    'error' => 'Choisir cette version remplace le planning de la saison et supprime ses plannings secondaires.',
                    'count' => \count($overlaysToDelete),
                    'overlays' => array_map(static fn (CalendarEntry $e): array => [
                        'entryId' => $e->getId(),
                        'title' => $e->getTitle(),
                        'overlayScheduleId' => $e->getOverlayScheduleId(),
                    ], $overlaysToDelete),
                ], Response::HTTP_CONFLICT);
            }
        }

        // Atomique : suppression des plans secondaires + pointeur + suppression des
        // versions sœurs commitent ensemble (un échec en cours de route ne doit pas
        // laisser un plan à moitié basculé).
        $this->entityManager->wrapInTransaction(function () use ($schedule, $season, $siblings, $overlaysToDelete, $entryId, $overlayEntry): void {
            foreach ($overlaysToDelete as $entry) {
                // force : le gestionnaire a explicitement confirmé la destruction.
                $this->overlayManager->deleteOverlayForEntry($entry, force: true);
            }

            // ADR-0002 inv. 1 — VALIDER = POINTER. Seule vérité : « validé » se dérive
            // du pointeur, il n'y a plus de statut pour le dire.
            if (!$this->schedulePlanProvisioner->choose($schedule)) {
                throw new ConflictHttpException('Cette version n\'est rattachée à aucun planning — impossible de la choisir.');
            }

            // La ★ (photo chargée) peut être posée sur une sœur qu'on s'apprête à
            // supprimer : la repointer sur la version choisie, dont la photo devient
            // la vérité (inv. 17 — la ★ reste, c'est l'auto-POINTEUR qui est mort).
            if ($season instanceof Season && null !== $season->getLiveContextScheduleId()) {
                foreach ($siblings as $sibling) {
                    if ($sibling->getId() === $season->getLiveContextScheduleId()) {
                        $season->setLiveContextScheduleId($schedule->getId());
                        break;
                    }
                }
            }

            // Le plan de la période pointe sa version choisie.
            if (null !== $entryId && $overlayEntry instanceof CalendarEntry) {
                $overlayEntry->setOverlayScheduleId($schedule->getId());
            }

            // inv. 1 : les versions non choisies sont SUPPRIMÉES (plus de filet
            // ARCHIVED). Les pointeurs ont tous été déplacés sur la gagnante ci-dessus.
            foreach ($siblings as $sibling) {
                $this->overlayManager->deleteVersion($sibling);
            }
        });

        return $this->json(['id' => $schedule->getId(), 'chosen' => true], Response::HTTP_OK);
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
