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
        $entity->setParentEntryId($input->parentEntryId);
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
        //
        // La garde porte sur « cette période a-t-elle un PLAN ? » : depuis le lot C une
        // closure/holiday a TOUJOURS un plan (né du geste, cf. processPost). Geler leur
        // identité revient donc à dire : le type et la fenêtre d'une période se choisissent
        // à sa création, et se corrigent en la supprimant puis en la recréant — ce que
        // l'UI impose déjà (elle n'expose aucun PUT ; ce verbe n'est atteignable qu'en
        // direct sur l'API).
        //
        // Ce choix rend inatteignables, PAR CONSTRUCTION, deux défauts que les rounds 1
        // et 2 du code-review avaient trouvés : la rétrogradation qui détruit un plan, et
        // la fenêtre du plan qui se périme quand on corrige les dates de sa période. Une
        // machinerie de synchronisation les réparait ; ne pas les laisser exister est plus
        // sûr. Un cutoff/mutualisation (sans plan) reste librement promouvable.
        if ($this->schedulePlanProvisioner->periodPlanExists($entity->getId())) {
            $kindChanged = null !== $input->kind && $this->parseKind($input->kind) !== $entity->getKind();
            $periodTypeChanged = null !== $input->periodType && $this->parsePeriodType($input->periodType) !== $entity->getPeriodType();
            $startChanged = null !== $input->startDate && $this->parseDate($input->startDate)->format('Y-m-d') !== $entity->getStartDate()->format('Y-m-d');
            $endChanged = null !== $input->endDate && $this->parseDate($input->endDate)->format('Y-m-d') !== $entity->getEndDate()->format('Y-m-d');
            // schoolHolidayId fait partie de l'identité : il dit QUELLES vacances la
            // période adapte, et le cockpit apparie ses cartes dessus (RadarPanel,
            // DayDialog). Le remapper laisserait la carte Toussaint proposer « Adapter »
            // sur le plan bâti pour février.
            $holidayChanged = null !== $input->schoolHolidayId && $input->schoolHolidayId !== $entity->getSchoolHolidayId();
            if ($kindChanged || $periodTypeChanged || $startChanged || $endChanged || $holidayChanged) {
                throw new UnprocessableEntityHttpException('Cette période porte un planning : son type, sa fenêtre et les vacances qu’elle adapte sont figés. Supprimez la période (son planning et ses versions partent avec) puis recréez-la.');
            }
        }

        // Le rattachement à une mère fait partie de l'identité (P2-5 E1) : il se
        // choisit à la création, jamais au PUT — re-parenter déplacerait les datées
        // héritées et la couverture sous les pieds du plan déjà bâti.
        if (null !== $input->parentEntryId && $input->parentEntryId !== $entity->getParentEntryId()) {
            throw new UnprocessableEntityHttpException('Le rattachement d’une semaine à sa période se choisit à la création.');
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
            // Semaine enfant (P2-5 E1) : valider la MÈRE avant de créer quoi que ce
            // soit — sous le verrou de son plan-scope, pour sérialiser avec une
            // génération « en bloc » concurrente (exclusivité bloc/semaines).
            if (null !== $input->parentEntryId) {
                $this->schedulePlanProvisioner->lockPlanScope($input->parentEntryId);
                $this->assertValidWeekChild($input, $clubId);
            }

            $output = parent::processPost($input, $clubId, $seasonId);
            // Le flush du parent rend la ligne visible à la relecture SQL brute du
            // provisioner — même connexion, même transaction.
            $this->provisionIfPlanBearing($output);

            return $output;
        });
    }

    /**
     * Un PUT ne peut PROMOUVOIR qu'une période sans plan (cutoff/mutualisation →
     * closure/holiday) : la garde d'identité ci-dessus refuse tout changement dès qu'un
     * plan existe. Cette promotion est le geste « ajuster », elle crée donc le plan —
     * sans quoi la période promue resterait inconfigurable à vie, plus rien ne créant un
     * plan a posteriori. Atomique pour la même raison que le POST.
     *
     * Aucune synchronisation, aucune suppression : provisionPeriodPlan ne fait que
     * naître-si-absent. Le reste est rendu impossible en amont plutôt que réparé après.
     *
     * @param array<string, mixed> $uriVariables
     * @param CalendarEntryInput   $input
     */
    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        return $this->entityManager->wrapInTransaction(function () use ($input, $uriVariables, $clubId, $seasonId): object {
            // Verrou de plan-scope AVANT l'UPDATE de l'entrée : tout autre écrivain du
            // scope (deleteEntryAndCascade, linkSchedule) prend le verrou consultatif PUIS
            // touche les lignes. Laisser le flush du parent verrouiller d'abord la ligne
            // calendar_entry inverserait l'ordre, et un PUT concurrent d'un DELETE de la
            // même période formerait un cycle ABBA (Postgres en tuerait un en 40P01 → 500).
            $entryId = $uriVariables['id'] ?? null;
            if (\is_string($entryId)) {
                $this->schedulePlanProvisioner->lockPlanScope($entryId);
            }

            $output = parent::processPut($input, $uriVariables, $clubId, $seasonId);
            $this->provisionIfPlanBearing($output);

            return $output;
        });
    }

    protected function mapEntityToOutput(object $entity): CalendarEntryResource
    {
        return CalendarEntryResource::fromEntity($entity);
    }

    /**
     * P2-5 E1 — garde du POST d'une semaine enfant : la mère existe (même club —
     * RLS + filtre saison la masquent sinon), porte un plan (closure/holiday),
     * n'est pas elle-même un enfant (1 seul niveau), le type de l'enfant est celui
     * de la mère, et son plan « bloc » n'a AUCUNE version (exclusivité : une
     * période déjà générée d'un bloc ne se découpe pas — supprimer ses versions
     * d'abord). Appelée SOUS le verrou du plan-scope de la mère.
     */
    private function assertValidWeekChild(CalendarEntryInput $input, ?string $clubId): void
    {
        $parent = $this->entityManager->getRepository(CalendarEntry::class)->find((string) $input->parentEntryId);
        if (!$parent instanceof CalendarEntry || (null !== $clubId && $parent->getClubId() !== $clubId)) {
            throw new UnprocessableEntityHttpException('La période mère n’existe pas.');
        }
        if (null !== $parent->getParentEntryId()) {
            throw new UnprocessableEntityHttpException('Une semaine ne peut pas être découpée à son tour (un seul niveau).');
        }
        $parentType = $parent->getPeriodType();
        if (!\in_array($parentType, [CalendarEntryPeriodType::CLOSURE, CalendarEntryPeriodType::HOLIDAY], true)) {
            throw new UnprocessableEntityHttpException('Seule une période à plan (fermeture/vacances) se découpe en semaines.');
        }
        if ($this->parsePeriodType($input->periodType) !== $parentType) {
            throw new UnprocessableEntityHttpException('Une semaine hérite du type de sa période mère.');
        }
        $parentPlanId = $this->schedulePlanProvisioner->periodPlanId($parent->getId());
        if (null !== $parentPlanId && $this->planHasVersions($parentPlanId)) {
            throw new UnprocessableEntityHttpException('Cette période a déjà été adaptée d’un bloc : supprimez d’abord ses versions pour la découper en semaines.');
        }
        // La FENÊTRE de l'enfant doit toucher celle de la mère : une semaine que
        // l'événement ne couvre pas hériterait ses contraintes datées sans raison.
        $childStart = (string) $input->startDate;
        $childEnd = (string) $input->endDate;
        if ($childEnd < $parent->getStartDate()->format('Y-m-d') || $childStart > $parent->getEndDate()->format('Y-m-d')) {
            throw new UnprocessableEntityHttpException('La semaine ne recouvre pas la période mère.');
        }
        // …et rester UNE semaine (≤ 7 jours — le clamp saison peut la rogner, jamais
        // l'allonger). Sans cette garde, un « enfant » de 2 mois hériterait le
        // venue_closed date-blind sur toute sa fenêtre (revue #262 round 2).
        $days = (int) new DateTimeImmutable($childStart)->diff(new DateTimeImmutable($childEnd))->format('%a') + 1;
        if ($days > 7) {
            throw new UnprocessableEntityHttpException('Une semaine enfant couvre au plus 7 jours (lundi→dimanche).');
        }
        // Anti-CHEVAUCHEMENT entre semaines d'une même mère (pas seulement le même
        // lundi) : deux plans de semaine qui se recouvrent = deux overlays actifs
        // possibles sur les mêmes jours, sans vainqueur défini (revue #262 round 1).
        $overlap = $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM calendar_entry WHERE parent_entry_id = :pid AND start_date <= :end AND end_date >= :start LIMIT 1',
            ['pid' => $parent->getId(), 'start' => $childStart, 'end' => $childEnd],
        );
        if (false !== $overlap) {
            throw new UnprocessableEntityHttpException('Cette semaine chevauche une semaine déjà découpée pour cette période.');
        }
    }

    /** Le plan porte-t-il au moins une version ? SQL brut (hors season_filter), RLS scope le club. */
    private function planHasVersions(string $schedulePlanId): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM schedule WHERE schedule_plan_id = :pid LIMIT 1',
            ['pid' => $schedulePlanId],
        );
    }

    /**
     * N'entre dans le provisioner que pour ce qui peut porter un plan (inv. 9) : un
     * event ou un cutoff y paierait une transaction imbriquée, un verrou consultatif et
     * deux SELECT pour s'entendre répondre « pas de plan ». Le provisioner reste
     * défensif de son côté — c'est lui qui fait autorité sur le mapping type → plan.
     */
    private function provisionIfPlanBearing(CalendarEntryResource $output): void
    {
        if (\in_array($output->periodType, ['closure', 'holiday'], true)) {
            $this->schedulePlanProvisioner->provisionPeriodPlan($output->id);
        }
    }

    /** @param array<string, mixed> $uriVariables */
    private function deleteEntryAndCascade(array $uriVariables, ?string $clubId): void
    {
        // Delete the overlay versions BEFORE the parent removes the entry (we need
        // the entry managed to drive deleteOverlayForEntry). Guard club ownership
        // inline so a cross-club delete deletes nothing before the parent throws 403.
        $id = $uriVariables['id'] ?? null;

        // P2-5 E1 : supprimer une MÈRE emporte ses semaines enfants — chacune par la
        // même cascade complète (plan + versions + réglages + reminders). Récursion
        // bornée par construction : un enfant n'est jamais parent (garde au POST).
        // Verrou du plan-scope AVANT l'énumération : le POST d'un enfant concurrent
        // tient le même verrou — énumérer avant laisserait survivre son enfant en
        // orphelin au plan fantôme (TOCTOU, revue #262 round 1). Ré-entrant : les
        // étapes plus bas le reprennent sans coût.
        if (\is_string($id) && '' !== $id) {
            $this->schedulePlanProvisioner->lockPlanScope($id);
            foreach ($this->entityManager->getRepository(CalendarEntry::class)->findBy(['parentEntryId' => $id]) as $child) {
                if (null === $clubId || $child->getClubId() === $clubId) {
                    $this->deleteEntryAndCascade(['id' => $child->getId()], $clubId);
                }
            }
        }
        // Capturé AVANT deletePeriodPlan : depuis le lot C2 les réglages de la période sont
        // ancrés au PLAN (inv. 5), or c'est le plan qu'on détruit juste en dessous — après
        // quoi plus rien ne relie ses réglages à cette période, et ils orphelineraient.
        $schedulePlanId = null;
        if (\is_string($id) && '' !== $id) {
            $entry = $this->entityManager->getRepository(CalendarEntry::class)->find($id);
            if ($entry instanceof CalendarEntry && (null === $clubId || $entry->getClubId() === $clubId)) {
                $schedulePlanId = $this->schedulePlanProvisioner->periodPlanId($entry->getId());
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
            // Tous les réglages de la période pendent au PLAN (inv. 5, lots C2-C3) — d'où
            // l'id capturé AVANT sa destruction, sans quoi ils orphelineraient.
            if (null !== $schedulePlanId) {
                foreach ($this->entityManager->getRepository(TeamPeriodOverride::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $override) {
                    $this->entityManager->remove($override);
                }
                // …and the period's constraint toggles (which permanent constraints it disabled).
                foreach ($this->entityManager->getRepository(ConstraintPeriodOverride::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $override) {
                    $this->entityManager->remove($override);
                }
                // Les créneaux prêtés pour cette période (« la mairie me prête ce gymnase
                // POUR cet ajustement ») et ses réservations : des RÉPONSES, donc au plan.
                foreach ($this->entityManager->getRepository(VenueTrainingSlot::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $slot) {
                    $this->entityManager->remove($slot);
                }
                foreach ($this->entityManager->getRepository(Reservation::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $reservation) {
                    $this->entityManager->remove($reservation);
                }
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
