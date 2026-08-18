<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ReservationResource;
use App\Dto\ReservationInput;
use App\Entity\Reservation;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\LockLevel;
use App\Service\PlanVenueClosures;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @extends AbstractStateProcessor<Reservation, ReservationInput, ReservationResource>
 */
class ReservationStateProcessor extends AbstractStateProcessor
{
    use AssertsSchedulePlanExistsTrait;

    private PlanVenueClosures $planVenueClosures;

    #[Required]
    public function setPlanVenueClosures(PlanVenueClosures $planVenueClosures): void
    {
        $this->planVenueClosures = $planVenueClosures;
    }

    protected function getEntityClass(): string
    {
        return Reservation::class;
    }

    /**
     * Deleting a reservation must UNDO its pin. A reservation is echoed HARD in
     * the solver output and materialised by ScheduleResultImporter as a durable
     * HARD ScheduleSlotTemplate; findBaseSlotTemplates would otherwise re-inject
     * that orphaned pin on every future generation, so purge the matching
     * materialised template(s) alongside the reservation.
     */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        $reservation = $this->entityManager->find(Reservation::class, $uriVariables['id'] ?? null);
        if (!$reservation instanceof Reservation) {
            throw new NotFoundHttpException('Resource not found');
        }
        if (null !== $clubId && $reservation->getClubId() !== $clubId) {
            throw new AccessDeniedHttpException('Access denied');
        }

        $materialised = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
            'clubId' => $reservation->getClubId(),
            'seasonId' => $reservation->getSeasonId(),
            'teamId' => $reservation->getTeamId(),
            'venueId' => $reservation->getVenueId(),
            'dayOfWeek' => $reservation->getDayOfWeek(),
            'startTime' => $reservation->getStartTime(),
            'lockLevel' => LockLevel::HARD,
        ]);
        foreach ($materialised as $template) {
            $this->entityManager->remove($template);
        }

        $this->entityManager->remove($reservation);
        $this->entityManager->flush();

        // Ce override court-circuite parent::processDelete → il doit émettre
        // lui-même la trace RGPD (revue PR-4 : angle mort du choke point).
        $actor = $this->actorSecurity?->getUser();
        $this->auditTrail?->record(
            AuditAction::ENTITY_DELETED,
            $actor instanceof User ? $actor->getId() : null,
            $clubId,
            'Reservation',
            $reservation->getId(),
        );
    }

    /**
     * @param ReservationInput $input
     */

    /**
     * Le flush a lieu dans le socle : la violation de FK d'une suppression de plan
     * CONCURRENTE ne peut être rattrapée qu'ici, autour de l'appel parent.
     *
     * @param ReservationInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPost($input, $clubId, $seasonId));
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param ReservationInput     $input
     */
    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        return $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPut($input, $uriVariables, $clubId, $seasonId));
    }

    protected function createEntityFromInput(object $input): Reservation
    {
        // clubId + seasonId are set by AbstractStateProcessor from the tenant/season
        // context. No SEC-07 management gate — reservations are a wizard write, like
        // constraints and venue slots.
        $entity = new Reservation;
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if (null !== $input->startTime) {
            $entity->setStartTime($input->startTime);
        }
        if (null !== $input->durationMinutes) {
            $entity->setDurationMinutes($input->durationMinutes);
        }
        $this->assertSchedulePlanExists($this->entityManager, $input->schedulePlanId);
        $this->assertVenueOpen($input->schedulePlanId, $input->venueId, $input->dayOfWeek);
        $entity->setSchedulePlanId($input->schedulePlanId);

        return $entity;
    }

    /**
     * @param Reservation      $entity
     * @param ReservationInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // No PUT operation — reservations are created or deleted, not edited.
    }

    /**
     * @param Reservation $entity
     */
    protected function mapEntityToOutput(object $entity): ReservationResource
    {
        return ReservationResource::fromEntity($entity);
    }

    /**
     * On ne réserve pas un gymnase que la période rend indisponible CE jour-là. L'indisponibilité
     * est désormais INFORMATIVE (décision fondateur 2026-08-18) : on suit l'ÉTAT EFFECTIF de la
     * MAISON UNIQUE — l'incident déclaré COMPOSÉ avec le masque manuel du plan. Un jour rouvert
     * OPEN redevient réservable ; un jour décoché à la main devient refusé. Le message distingue
     * la cause (indisponibilité déclarée vs décochage manuel). On refuse à la SOURCE (422) ; les
     * réservations DÉJÀ posées ne sont ni supprimées ni modifiées (décision fondateur : « on ne
     * fait pas de modification passive, on alerte » — l'alerte vit côté récap, `unservedReservationIds`).
     */
    private function assertVenueOpen(?string $schedulePlanId, ?string $venueId, ?int $dayOfWeek): void
    {
        // Trois retours anticipés séparés (et non un `||` — que Rector réécrirait en `in_array`,
        // perdant le narrowing non-null dont a besoin `effectiveStateForPlan(string)`).
        if (null === $schedulePlanId) {
            return;
        }
        if (null === $venueId || null === $dayOfWeek) {
            return;
        }
        $state = $this->planVenueClosures->effectiveStateForPlan($schedulePlanId);
        $fullyClosed = isset($state['fullyClosedVenueIds'][$venueId]);
        $dayClosed = isset($state['effectiveClosedWeekdaysByVenue'][$venueId][$dayOfWeek]);
        if (!$fullyClosed && !$dayClosed) {
            return;
        }

        // Un jour fermé À LA MAIN (masque CLOSED sans indisponibilité déclarée dessous) : la
        // cause est le décochage, pas une fermeture — rien à nommer, on invite à le rouvrir.
        if (!$fullyClosed && isset($state['manualClosedWeekdaysByVenue'][$venueId][$dayOfWeek])) {
            throw new UnprocessableEntityHttpException('Ce gymnase est fermé ce jour-là pour cette période (jour décoché) : la séance ne peut pas y être réservée. Rouvrez ce jour, ou choisissez un autre créneau.');
        }

        $label = PlanVenueClosures::describeForVenue($state['summaries'], $venueId);
        $cause = $fullyClosed ? 'est indisponible sur toute la période' : 'est fermé ce jour-là';

        // Même patron surfaçant le message que `assertSchedulePlanExists` (le
        // `ValidationException(string)` d'API Platform ne remonte pas son message).
        throw new UnprocessableEntityHttpException(\sprintf('Ce gymnase %s%s : la séance ne peut pas y être réservée. Choisissez un autre créneau, ou ajustez la fermeture.', $cause, null !== $label ? ' — ' . $label : ''));
    }
}
