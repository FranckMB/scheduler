<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ScheduleResource;
use App\Dto\ScheduleInput;
use App\Entity\CalendarEntry;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
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
     * A schedule carrying calendarEntryId is a period OVERLAY (palier B). Validate
     * the target entry (422) before creation, then stamp the inverse link
     * (CalendarEntry.overlayScheduleId) server-side — never trusting the client.
     *
     * @param ScheduleInput $input
     *
     * @return ScheduleResource
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        $entry = null;
        if (null !== $input->calendarEntryId) {
            $entry = $this->entityManager->getRepository(CalendarEntry::class)->find($input->calendarEntryId);
            // Explicit club check (do not rely on RLS alone — the EM identity map
            // can surface a cross-club entry loaded earlier in the same request).
            if (!$entry instanceof CalendarEntry || (null !== $clubId && $entry->getClubId() !== $clubId)) {
                throw new UnprocessableEntityHttpException('Unknown calendar entry.');
            }
            if (CalendarEntryKind::PERIOD !== $entry->getKind()) {
                throw new UnprocessableEntityHttpException('Only a period entry can carry an overlay.');
            }
            if (!\in_array($entry->getPeriodType(), [CalendarEntryPeriodType::CLOSURE, CalendarEntryPeriodType::HOLIDAY], true)) {
                throw new UnprocessableEntityHttpException('Overlay generation is only supported for closure and holiday periods.');
            }
            // planning-versions: a period may carry SEVERAL overlay versions
            // (V1, V2…) like a season plan; the new one becomes the active overlay
            // (pointer set below). Only refuse while a sibling version of THIS
            // period is still solving — a running solve must never be overwritten
            // (mirror of the season in-flight guard).
            $inFlight = $this->entityManager->getRepository(Schedule::class)->count([
                'clubId' => $entry->getClubId(),
                'seasonId' => $entry->getSeasonId(),
                'calendarEntryId' => $entry->getId(),
                'status' => [ScheduleStatus::PENDING, ScheduleStatus::GENERATING],
            ]);
            if ($inFlight > 0) {
                throw new ConflictHttpException('Une génération est déjà en cours pour cette période — attendez sa fin.');
            }
            // ADR-0002 inv. 13 : un plan secondaire se bâtit SUR le calendrier de
            // base — le plan SEASON doit avoir une version choisie.
            $this->socleGuard->assertSeasonPlanChosen($entry->getSeasonId());
            // Bind the overlay to the ENTRY's season (not the active one) so the
            // build reads the right season's structure + dated constraints.
            $seasonId = $entry->getSeasonId();
        }

        // Stamp tenant/season server-side (parent::processPost's job, inlined so
        // the persist can join ONE transaction with the plan link below).
        /** @var Schedule $schedule */
        $schedule = $this->createEntityFromInput($input);
        $resolvedSeasonId = $this->resolveSeasonId($clubId, $seasonId);
        // A schedule with no resolvable season is invalid — fail with a controlled
        // 422 rather than an uninitialized-property TypeError once linkSchedule
        // (or the flush) dereferences the never-set seasonId.
        if (null === $resolvedSeasonId) {
            throw new UnprocessableEntityHttpException('No season could be resolved for this schedule.');
        }
        if (null !== $clubId) {
            $schedule->setClubId($clubId);
        }
        $schedule->setSeasonId($resolvedSeasonId);

        // Atomic (like RegenerateController): the row, its plan link and the
        // overlay pointer commit together. A linkSchedule failure must never
        // leave a committed-but-unlinked schedule occupying the period slot.
        $this->entityManager->wrapInTransaction(function () use ($schedule, $entry): void {
            $this->entityManager->persist($schedule);

            // ADR-0002 Lot A: link the new version to its SchedulePlan (the
            // CLOSURE/HOLIDAY plan is created lazily on a period's first version).
            $this->schedulePlanProvisioner->linkSchedule($schedule);

            if ($entry instanceof CalendarEntry) {
                // The new version becomes the ACTIVE overlay only if the period has
                // no usable one to fall back on — mirror of the season baseline,
                // which moves only on validation. This keeps a good V1 shown while
                // a regenerated V2 solves (or fails): validating V2 later flips the
                // pointer (ValidateScheduleController). Otherwise a failed
                // regenerate would strand the period on an empty draft.
                $activeId = $entry->getOverlayScheduleId();
                $active = null !== $activeId ? $this->entityManager->getRepository(Schedule::class)->find($activeId) : null;
                $activeIsUsable = $active instanceof Schedule && ScheduleStatus::COMPLETED === $active->getStatus();
                if (!$activeIsUsable) {
                    $entry->setOverlayScheduleId($schedule->getId());
                }
            }

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
        // Overlay marker (palier B) — POST only; never mutated on PUT.
        $entity->setCalendarEntryId($input->calendarEntryId);

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
        if (null !== $schedule->getCalendarEntryId() || ScheduleStatus::COMPLETED !== $schedule->getStatus()) {
            return false;
        }

        $others = $this->entityManager->getRepository(Schedule::class)->count([
            'clubId' => $schedule->getClubId(),
            'seasonId' => $schedule->getSeasonId(),
            'calendarEntryId' => null,
            'status' => ScheduleStatus::COMPLETED,
        ]);

        return $others <= 1;
    }
}
