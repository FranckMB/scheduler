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
            // An overlay is built ON the socle: the season must have a baseline
            // (otherwise the club would be onboarded with only an overlay, no base).
            $season = $this->entityManager->getRepository(Season::class)->find($entry->getSeasonId());
            if (!$season instanceof Season || null === $season->getBaselineScheduleId()) {
                throw new UnprocessableEntityHttpException('The season has no baseline plan yet — validate the base plan first.');
            }
            // Secondary plans are locked until the main plan is VALIDATED (cockpit
            // state 3) — same invariant the SocleGuard enforces on generation.
            if (null === $season->getSocleValidatedAt()) {
                throw new ConflictHttpException('Validez le planning principal avant de créer un planning secondaire.');
            }
            // Bind the overlay to the ENTRY's season (not the active one) so the
            // build reads the right season's structure + dated constraints.
            $seasonId = $entry->getSeasonId();
        }

        /** @var ScheduleResource $output */
        $output = parent::processPost($input, $clubId, $seasonId);

        if ($entry instanceof CalendarEntry) {
            // The new version becomes the ACTIVE overlay only if the period has no
            // usable one to fall back on — mirror of the season baseline, which
            // moves only on validation. This keeps a good V1 shown while a
            // regenerated V2 solves (or fails): validating V2 later flips the
            // pointer (ValidateScheduleController). Otherwise a failed regenerate
            // would strand the previously-adapted period on an empty draft.
            $activeId = $entry->getOverlayScheduleId();
            $active = null !== $activeId ? $this->entityManager->getRepository(Schedule::class)->find($activeId) : null;
            $activeIsUsable = $active instanceof Schedule && \in_array($active->getStatus(), [ScheduleStatus::COMPLETED, ScheduleStatus::VALIDATED], true);
            if (!$activeIsUsable) {
                $entry->setOverlayScheduleId($output->id);
            }
            $this->entityManager->flush();
        }

        return $output;
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
                // The baseline is never deletable (§9 cockpit) — it anchors the whole
                // season (socle, overlays, /api/me.baselineScheduleId).
                $season = $this->entityManager->getRepository(Season::class)->find($schedule->getSeasonId());
                if ($season instanceof Season && $season->getBaselineScheduleId() === $schedule->getId()) {
                    throw new ConflictHttpException('The baseline schedule cannot be deleted. Designate another baseline first.');
                }
                // A validated schedule is read-only: reopen it before deleting.
                if (ScheduleStatus::VALIDATED === $schedule->getStatus()) {
                    throw new ConflictHttpException('This schedule is validated (read-only). Reopen it before deleting.');
                }
                // A version whose solve is still running cannot be deleted out
                // from under the worker (its import would resurrect artifacts).
                if (\in_array($schedule->getStatus(), [ScheduleStatus::PENDING, ScheduleStatus::GENERATING], true)) {
                    throw new ConflictHttpException('This schedule is still generating. Wait for it to finish before deleting.');
                }
                $this->overlayManager->purgeScheduleArtifacts($schedule);
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
        // A validated schedule is read-only: reopen it (POST /reopen) before editing.
        if (ScheduleStatus::VALIDATED === $entity->getStatus()) {
            throw new ConflictHttpException('This schedule is validated (read-only). Reopen it before editing.');
        }
        // Status transitions go through the dedicated endpoints (generate/validate/reopen),
        // never a free-form PUT. The field is accepted but IGNORED (never applied):
        // the frontend rename echoes a possibly-stale cached status, so rejecting a
        // mismatch would 409 legitimate renames — while silently ignoring still makes
        // fabricating a COMPLETED plan without generation impossible.
        if ('VALIDATED' === $input->status) {
            throw new ConflictHttpException('Use POST /schedules/{id}/validate to validate a schedule.');
        }
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
}
