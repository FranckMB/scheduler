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
use App\Repository\SeasonRepository;
use App\Service\OverlayManager;
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
        SeasonRepository $seasonRepository,
        private readonly OverlayManager $overlayManager,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonRepository);
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
            if (null !== $entry->getOverlayScheduleId()) {
                throw new UnprocessableEntityHttpException('This period already has an overlay schedule.');
            }
            // An overlay is built ON the socle: the season must have a baseline
            // (otherwise the club would be onboarded with only an overlay, no base).
            $season = $this->entityManager->getRepository(Season::class)->find($entry->getSeasonId());
            if (!$season instanceof Season || null === $season->getBaselineScheduleId()) {
                throw new UnprocessableEntityHttpException('The season has no baseline plan yet — validate the base plan first.');
            }
            // Bind the overlay to the ENTRY's season (not the active one) so the
            // build reads the right season's structure + dated constraints.
            $seasonId = $entry->getSeasonId();
        }

        /** @var ScheduleResource $output */
        $output = parent::processPost($input, $clubId, $seasonId);

        if ($entry instanceof CalendarEntry) {
            $entry->setOverlayScheduleId($output->id);
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
        if (null !== $input->status) {
            $status = ScheduleStatus::tryFrom($input->status);
            if (null !== $status) {
                $entity->setStatus($status);
            }
        }
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
