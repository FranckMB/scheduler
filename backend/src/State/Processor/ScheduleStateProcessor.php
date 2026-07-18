<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ScheduleResource;
use App\Dto\ScheduleInput;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Service\OverlayManager;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @extends AbstractStateProcessor<Schedule, ScheduleInput, ScheduleResource>
 */
class ScheduleStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        \App\Service\ManagementAccessGuard $managementAccessGuard,
        private readonly OverlayManager $overlayManager,
        private readonly \App\Service\SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly \App\Service\SocleGuard $socleGuard,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    /** SEC-07: schedule create/rename/delete is cockpit management surface. */
    protected function requiresManagementRole(): bool
    {
        return true;
    }

    protected function getEntityClass(): string
    {
        return Schedule::class;
    }

    /**
     * ADR-0002 C4 : POST crée une version SOUS un plan nommé (`schedulePlanId`) — un overlay
     * de période — ou, si omis, sous le plan SEASON (le socle). On valide que le plan
     * appartient au club, on en dérive la saison, et pour un overlay on applique les gardes
     * de période (une génération en cours bloque ; le socle doit être pointé, inv. 13). La
     * version créée n'est PAS montrée tant qu'elle n'est pas validée (lot D-b : « actif » =
     * plan.chosenScheduleId, plus de pointeur inverse posé à la création).
     *
     * @param ScheduleInput $input
     *
     * @return ScheduleResource
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        // La version s'écrit dans la saison ACTIVE de la requête — celle dont le parent vient
        // de vérifier qu'elle est éditable (assertWritable, garde archive). On la résout AVANT
        // de valider le plan, pour exiger qu'il lui appartienne (voir plus bas).
        $resolvedSeasonId = $this->resolveSeasonId($clubId, $seasonId);
        if (null === $resolvedSeasonId) {
            throw new UnprocessableEntityHttpException('No season could be resolved for this schedule.');
        }

        $entry = null;
        $planId = $input->schedulePlanId;
        if (null !== $planId) {
            $plan = $this->schedulePlanProvisioner->fetchPlanContext($planId);
            // Club ET saison : le plan doit être du club ET de la saison active. Nommer un plan
            // d'une AUTRE saison (archivée, N-1) contournerait la garde archive — le POST passe
            // assertWritable sur la saison active, puis s'estampillait de la saison du plan. Avant
            // C4, le find() season-filtré de l'entrée refusait déjà ce cas (422) ; fetchPlanContext
            // est en SQL brut (non filtré), on rétablit donc la garde explicitement. Check club
            // explicite aussi (l'identity map peut surfacer une ligne d'un autre club).
            if (null === $plan
                || (null !== $clubId && $plan['clubId'] !== $clubId)
                || $plan['seasonId'] !== $resolvedSeasonId) {
                throw new UnprocessableEntityHttpException('Unknown schedule plan.');
            }
            if (SchedulePlanType::SEASON !== $plan['type']) {
                // Overlay (plan CLOSURE/HOLIDAY). Une sœur en cours de solve bloque : on ne
                // réécrit jamais un solve en vol (miroir de la garde saison).
                $inFlight = $this->entityManager->getRepository(Schedule::class)->count([
                    'clubId' => $plan['clubId'],
                    'seasonId' => $resolvedSeasonId,
                    'schedulePlanId' => $planId,
                    'status' => [ScheduleStatus::PENDING, ScheduleStatus::GENERATING],
                ]);
                if ($inFlight > 0) {
                    throw new ConflictHttpException('Une génération est déjà en cours pour cette période — attendez sa fin.');
                }
                // inv. 13 : un plan secondaire se bâtit SUR le calendrier de base pointé.
                $this->socleGuard->assertSeasonPlanChosen($resolvedSeasonId);
            }
        }

        /** @var Schedule $schedule */
        $schedule = $this->createEntityFromInput($input);
        if (null !== $clubId) {
            $schedule->setClubId($clubId);
        }
        $schedule->setSeasonId($resolvedSeasonId);

        // ADR-0002 C4 : le plan est explicite (overlay/période) ou, par défaut, le plan
        // SEASON de la saison (le socle). La version le porte AVANT linkSchedule, qui ne
        // fait plus que la numéroter.
        $resolvedPlanId = $planId ?? $this->schedulePlanProvisioner->ensureSeasonPlanId($resolvedSeasonId);
        if (null === $resolvedPlanId) {
            throw new UnprocessableEntityHttpException('No schedule plan could be resolved for this schedule.');
        }
        $schedule->setSchedulePlanId($resolvedPlanId);

        // Atomic (like RegenerateController): the row and its version number commit
        // together. A linkSchedule failure must never leave a committed-but-unnumbered
        // schedule occupying the period slot.
        $this->entityManager->wrapInTransaction(function () use ($schedule): void {
            $this->entityManager->persist($schedule);

            // ADR-0002 C4 : numérote la version dans son plan (déjà posé ci-dessus).
            // La version n'est pas « active » : elle le devient à la validation (le plan
            // pointe sa chosenScheduleId), jamais à la création (lot D-b).
            $this->schedulePlanProvisioner->linkSchedule($schedule);

            $this->entityManager->flush();
        });

        return $this->mapEntityToOutput($schedule);
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        // Purge the schedule's slots/diagnostics (no FK cascade) and reset any
        // period entry pointing at it, before the parent removes the row.
        $id = $uriVariables['id'] ?? null;
        if (\is_string($id) && '' !== $id) {
            $schedule = $this->entityManager->getRepository(Schedule::class)->find($id);
            if ($schedule instanceof Schedule && (null === $clubId || $schedule->getClubId() === $clubId)) {
                // ADR-0002 inv. 1 : la version CHOISIE ancre le plan — la rouvrir
                // (dépointer) avant de la supprimer.
                if ($this->schedulePlanProvisioner->isChosen($schedule->getId())) {
                    throw new ConflictHttpException('La version choisie ne peut pas être supprimée. Rouvrez le planning d\'abord.');
                }
                // A version whose solve is still running cannot be deleted out
                // from under the worker (its import would resurrect artifacts).
                if (\in_array($schedule->getStatus(), [ScheduleStatus::PENDING, ScheduleStatus::GENERATING], true)) {
                    throw new ConflictHttpException('This schedule is still generating. Wait for it to finish before deleting.');
                }
                // La DERNIÈRE version terminée du plan de la saison ancre la saison :
                // la supprimer laisserait un club établi sans aucun calendrier, donc
                // sans cockpit ni matchs (inv. 8/16 le renverrait au wizard guidé, ses
                // matchs orphelins). Rouvrir dépointe (inv. 2) mais ne doit pas ouvrir
                // cette porte : le geste pour remplacer un planning, c'est régénérer.
                if ($this->isLastFinishedSeasonVersion($schedule)) {
                    throw new ConflictHttpException('C\'est le seul planning de la saison — régénérez-en un autre plutôt que de supprimer celui-ci.');
                }
                // Atomique : purgeScheduleArtifacts relâche le pointeur via un
                // UPDATE brut qui s'auto-commit. Sans transaction, un échec du
                // remove/flush du parent laisserait le pointeur vidé alors que la
                // version survit (même idiome que deleteOverlayForEntry).
                $this->entityManager->wrapInTransaction(function () use ($schedule, $uriVariables, $clubId): void {
                    $this->overlayManager->purgeScheduleArtifacts($schedule);
                    parent::processDelete($uriVariables, $clubId);
                });

                return;
            }
        }

        parent::processDelete($uriVariables, $clubId);
    }

    /**
     * @param ScheduleInput $input
     */
    protected function createEntityFromInput(object $input): Schedule
    {
        $entity = new Schedule;
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        // SEC-07 review finding: a client-supplied status could fabricate a
        // COMPLETED/VALIDATED plan without ever running the solver (the PUT
        // path already forbids this — the POST path must match). Only DRAFT
        // may be set at creation; lifecycle transitions go through the
        // dedicated endpoints (generate/validate/reopen).
        if (null !== $input->status && ScheduleStatus::DRAFT->value !== $input->status) {
            throw new ConflictHttpException('A schedule is created as DRAFT; use the lifecycle endpoints to change its status.');
        }
        $entity->setStatus(ScheduleStatus::DRAFT);
        if (null !== $input->solverSeed) {
            $entity->setSolverSeed($input->solverSeed);
        }

        return $entity;
    }

    /**
     * @param Schedule      $entity
     * @param ScheduleInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // ADR-0002 inv. 1 : la version choisie est le planning en vigueur — la
        // rouvrir (dépointer) avant de l'éditer.
        if ($this->schedulePlanProvisioner->isChosen($entity->getId())) {
            throw new ConflictHttpException('La version choisie est le planning en vigueur. Rouvrez-le avant de l\'éditer.');
        }
        // Status transitions go through the dedicated endpoints (generate/validate/reopen),
        // never a free-form PUT. The field is accepted but IGNORED (never applied):
        // the frontend rename echoes a possibly-stale cached status, so rejecting a
        // mismatch would 409 legitimate renames — while silently ignoring still makes
        // fabricating a COMPLETED plan without generation impossible.
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->solverSeed) {
            $entity->setSolverSeed($input->solverSeed);
        }
    }

    /**
     * @param Schedule $entity
     */
    protected function mapEntityToOutput(object $entity): ScheduleResource
    {
        return ScheduleResource::fromEntity($entity);
    }

    /**
     * Cette version est-elle la seule version TERMINÉE du plan de la saison ? Les
     * overlays de période ne comptent pas : ils ont leur propre plan, et une période
     * sans planning est un état normal.
     */
    private function isLastFinishedSeasonVersion(Schedule $schedule): bool
    {
        // ADR-0002 C4 : « version de saison ? » = plan.type === SEASON. Garde de
        // SUPPRESSION → variante NON levante (planIsSeason) : un schedule sans plan est une
        // anomalie qui doit rester SUPPRIMABLE (purge, ruling 2026-07-17), jamais un 500.
        $planId = $schedule->getSchedulePlanId();
        if (ScheduleStatus::COMPLETED !== $schedule->getStatus() || !$this->schedulePlanProvisioner->planIsSeason($planId)) {
            return false;
        }

        // Les autres versions terminées du MÊME plan (= le plan SEASON, puisque planIsSeason).
        $others = $this->entityManager->getRepository(Schedule::class)->count([
            'clubId' => $schedule->getClubId(),
            'seasonId' => $schedule->getSeasonId(),
            'schedulePlanId' => $planId,
            'status' => ScheduleStatus::COMPLETED,
        ]);

        return $others <= 1;
    }
}
