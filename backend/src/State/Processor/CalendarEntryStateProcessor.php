<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\CalendarEntryResource;
use App\Dto\CalendarEntryInput;
use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\PeriodReminderLog;
use App\Entity\Reservation;
use App\Entity\TeamPeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Service\OverlayManager;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @extends AbstractStateProcessor<CalendarEntry, CalendarEntryInput, CalendarEntryResource>
 *
 * Palier A: writing a dated Constraint (Constraint.calendarEntryId) never touches
 * the BASE generation payload — dated constraints are excluded by
 * ConstraintRepository::findPermanentByClubSeason. Palier B: overlay generation
 * reads dated constraints via ScheduleConstraintBuilder::buildForOverlay, which
 * BYPASSES the schedule-input cache entirely, so no invalidation is needed here.
 */
class CalendarEntryStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        \App\Service\ManagementAccessGuard $managementAccessGuard,
        private readonly OverlayManager $overlayManager,
        private readonly \App\Service\SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return CalendarEntry::class;
    }

    /**
     * @param CalendarEntryInput $input
     */
    protected function createEntityFromInput(object $input): CalendarEntry
    {
        $entity = new CalendarEntry;
        $entity->setKind($this->parseKind($input->kind));
        $entity->setTitle($input->title ?? '');
        $entity->setStartDate($this->parseDate($input->startDate));
        $entity->setEndDate($this->parseDate($input->endDate));
        $entity->setIsDisruptive($input->isDisruptive ?? false);
        $entity->setPeriodType($this->parsePeriodType($input->periodType));
        $entity->setSchoolHolidayId($input->schoolHolidayId);
        $entity->setStatus($this->parseStatus($input->status));
        $entity->setCreatedBy($input->createdBy);

        return $entity;
    }

    /**
     * @param CalendarEntry      $entity
     * @param CalendarEntryInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // A period carrying a generated overlay cannot change identity: the overlay
        // was built for THIS kind/periodType/window. Mutating any of them would leave
        // the overlay semantically wrong (or crash the next regeneration in
        // buildForOverlay). Title/status/isDisruptive edits stay allowed.
        if (null !== $entity->getOverlayScheduleId()) {
            $kindChanged = null !== $input->kind && $this->parseKind($input->kind) !== $entity->getKind();
            $periodTypeChanged = null !== $input->periodType && $this->parsePeriodType($input->periodType) !== $entity->getPeriodType();
            $startChanged = null !== $input->startDate && $this->parseDate($input->startDate)->format('Y-m-d') !== $entity->getStartDate()->format('Y-m-d');
            $endChanged = null !== $input->endDate && $this->parseDate($input->endDate)->format('Y-m-d') !== $entity->getEndDate()->format('Y-m-d');
            if ($kindChanged || $periodTypeChanged || $startChanged || $endChanged) {
                throw new UnprocessableEntityHttpException('This period has a generated overlay plan. Delete the overlay plan before changing the period kind, type or dates.');
            }
        }

        if (null !== $input->kind) {
            $kind = $this->parseKind($input->kind);
            $entity->setKind($kind);
            // Converting to an event clears period-only fields so the row can
            // never persist an inconsistent shape (kind=event + periodType set).
            if (CalendarEntryKind::EVENT === $kind) {
                $entity->setPeriodType(null);
                $entity->setSchoolHolidayId(null);
            }
        }
        if (null !== $input->title) {
            $entity->setTitle($input->title);
        }
        if (null !== $input->startDate) {
            $entity->setStartDate($this->parseDate($input->startDate));
        }
        if (null !== $input->endDate) {
            $entity->setEndDate($this->parseDate($input->endDate));
        }
        if (null !== $input->isDisruptive) {
            $entity->setIsDisruptive($input->isDisruptive);
        }
        if (null !== $input->periodType) {
            $entity->setPeriodType($this->parsePeriodType($input->periodType));
        }
        if (null !== $input->schoolHolidayId) {
            $entity->setSchoolHolidayId($input->schoolHolidayId);
        }
        if (null !== $input->status) {
            $entity->setStatus($this->parseStatus($input->status));
        }
        if (null !== $input->createdBy) {
            $entity->setCreatedBy($input->createdBy);
        }
    }

    /**
     * Deleting a period removes its overlay schedule (palier B) AND its dated
     * constraints — otherwise the overlay's slots/diagnostics and the dated
     * constraints orphan (invisible to generation and to the user).
     *
     * @param array<string, mixed> $uriVariables
     */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        // Atomique : la suppression du plan de période est un DELETE brut qui
        // s'auto-commit ; sans transaction, un échec plus bas (cascade, audit)
        // laisserait le plan détruit alors que la période survit — et une
        // ré-adaptation repartirait à V1 (le compteur monotone serait perdu).
        $this->entityManager->wrapInTransaction(function () use ($uriVariables, $clubId): void {
            $this->deleteEntryAndCascade($uriVariables, $clubId);
        });
    }

    /**
     * ADR-0002 lot C — LE PLAN NAÎT DU GESTE. Creating a CLOSURE/HOLIDAY entry IS
     * the gesture "ajuster cette période", so its plan is born here, not at the first
     * generation: the period's settings (inv. 5) are entered BEFORE any version exists
     * and must have a plan to hang off.
     *
     * ATOMIQUE, et c'est structurel : le plan est la SEULE porte (linkSchedule ne fait
     * plus que chercher, et choose() ne peut donc plus réparer). Sans transaction
     * englobante, un échec du provisioning après le flush du parent — wrapInTransaction
     * FERME l'EntityManager et relance — laisserait une période commitée sans plan, que
     * plus aucun chemin ne rattraperait : sa génération produirait une version non liée,
     * et sa validation un 409 définitif. Une période sans plan ne doit pas pouvoir
     * exister ; on préfère ne pas créer la période du tout.
     *
     * @param CalendarEntryInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->entityManager->wrapInTransaction(function () use ($input, $clubId, $seasonId): object {
            $output = parent::processPost($input, $clubId, $seasonId);
            // Le flush du parent rend la ligne visible à la relecture SQL brute du
            // provisioner — même connexion, même transaction. Un type non générant
            // (inv. 9) rend null : pas de plan, pas d'erreur.
            $this->schedulePlanProvisioner->syncPeriodPlan($output->id);

            return $output;
        });
    }

    /**
     * Toute écriture sur l'entrée RÉCONCILIE son plan (naissance / synchronisation de
     * la fenêtre / suppression) : le plan est la réponse à un événement, il doit suivre
     * l'événement. Promouvoir un cutoff en holiday crée le plan — c'est le geste ; le
     * rétrograder le supprime (inv. 9) ; corriger les dates recale sa fenêtre. Atomique
     * pour la même raison que le POST.
     *
     * @param array<string, mixed> $uriVariables
     * @param CalendarEntryInput   $input
     */
    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        return $this->entityManager->wrapInTransaction(function () use ($input, $uriVariables, $clubId, $seasonId): object {
            $output = parent::processPut($input, $uriVariables, $clubId, $seasonId);
            $this->schedulePlanProvisioner->syncPeriodPlan($output->id);

            return $output;
        });
    }

    protected function mapEntityToOutput(object $entity): CalendarEntryResource
    {
        return CalendarEntryResource::fromEntity($entity);
    }

    /** @param array<string, mixed> $uriVariables */
    private function deleteEntryAndCascade(array $uriVariables, ?string $clubId): void
    {
        // Delete the overlay BEFORE the parent removes the entry (we need the
        // entry to read overlayScheduleId). Guard club ownership inline so a
        // cross-club delete deletes nothing before the parent throws 403.
        $id = $uriVariables['id'] ?? null;
        if (\is_string($id) && '' !== $id) {
            $entry = $this->entityManager->getRepository(CalendarEntry::class)->find($id);
            if ($entry instanceof CalendarEntry && (null === $clubId || $entry->getClubId() === $clubId)) {
                // Verrou EN TÊTE : deleteOverlayForEntry balaie les versions juste
                // en dessous. Le prendre après (dans deletePeriodPlan) ne sérialise
                // rien — une création d'overlay concurrente s'intercale, et sa
                // version se retrouve liée à un plan qu'on vient de supprimer.
                $this->schedulePlanProvisioner->lockPlanScope($entry->getId());
                $this->overlayManager->deleteOverlayForEntry($entry);
                // ADR-0002 inv. 10 : supprimer une indisponibilité supprime SES plans.
                // Ici (et pas dans deleteOverlayForEntry, que la purge des périodes
                // échues appelle sur des entries qui, elles, SURVIVENT) : sinon
                // /api/schedule_plans garderait un plan fantôme nommant une période
                // supprimée, et une re-adaptation repartirait à V1.
                $this->schedulePlanProvisioner->deletePeriodPlan($entry->getId());
            }
        }

        // Parent runs the not-found / cross-club (403) guard and removes the
        // entry; only then do we cascade to its dated constraints.
        parent::processDelete($uriVariables, $clubId);

        if (\is_string($id) && '' !== $id) {
            // Per-row remove (not bulk DQL DELETE): the `constraint` table is a
            // reserved word and the tenant SQL filter injects an unquoted alias
            // on bulk deletes → syntax error. UnitOfWork removes quote correctly.
            $dated = $this->entityManager->getRepository(Constraint::class)->findBy(['calendarEntryId' => $id]);
            foreach ($dated as $constraint) {
                $this->entityManager->remove($constraint);
            }
            // Period-editable structure (B1): the period's own training slots and
            // team overrides are keyed on this entry — remove them too, else they orphan.
            foreach ($this->entityManager->getRepository(VenueTrainingSlot::class)->findBy(['calendarEntryId' => $id]) as $slot) {
                $this->entityManager->remove($slot);
            }
            foreach ($this->entityManager->getRepository(TeamPeriodOverride::class)->findBy(['calendarEntryId' => $id]) as $override) {
                $this->entityManager->remove($override);
            }
            // …and the period's constraint toggles (which permanent constraints it disabled).
            foreach ($this->entityManager->getRepository(ConstraintPeriodOverride::class)->findBy(['calendarEntryId' => $id]) as $override) {
                $this->entityManager->remove($override);
            }
            // A period's own reservations (dated pins) are keyed on the entry too.
            foreach ($this->entityManager->getRepository(Reservation::class)->findBy(['calendarEntryId' => $id]) as $reservation) {
                $this->entityManager->remove($reservation);
            }
            // …and any reminder logged for this period (else a ghost survives to the season purge).
            foreach ($this->entityManager->getRepository(PeriodReminderLog::class)->findBy(['calendarEntryId' => $id]) as $log) {
                $this->entityManager->remove($log);
            }
            $this->entityManager->flush();
        }
    }

    private function parseKind(?string $value): CalendarEntryKind
    {
        return CalendarEntryKind::tryFrom($value ?? '') ?? CalendarEntryKind::EVENT;
    }

    private function parsePeriodType(?string $value): ?CalendarEntryPeriodType
    {
        if (null === $value) {
            return null;
        }

        return CalendarEntryPeriodType::tryFrom($value);
    }

    private function parseStatus(?string $value): CalendarEntryStatus
    {
        return CalendarEntryStatus::tryFrom($value ?? '') ?? CalendarEntryStatus::ACTIVE;
    }

    private function parseDate(?string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value ?? 'now');
    }
}
