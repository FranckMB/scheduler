<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\CoachWish;
use App\Entity\Competition;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\Fixture;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SharedTrainingGroup;
use App\Entity\SharedTrainingGroupTeam;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\TeamPeriodOverride;
use App\Entity\TeamTagAssignment;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Entity\VenueUnavailability;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Enum\SchedulePlanType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cascade the logical children of a single deleted entity (team / venue / coach
 * / availability slot). Unlike SeasonDataPurger (whole-season wipe), this runs
 * on a per-entity Delete: the entities carry no ORM associations or DB foreign
 * keys — every link is a plain guid column — so nothing cascades on its own and
 * a bare remove() would orphan reservations, coach links, constraints and the
 * materialised HARD slot templates the solver reads back.
 *
 * Runs in the request's tenant context (RLS GUC already set to the club by
 * TenantFilterListener). The bulk DQL DELETE/UPDATE are scoped by the entity's
 * clubId + seasonId AND guarded by RLS at the DB — a cascade can never cross a
 * club. The tenant/season Doctrine filters are disabled around the statements:
 * like SeasonDataPurger, they alias the table name (invalid SQL for the
 * reserved-word `constraint` table); the explicit scope + RLS replace them.
 *
 * The caller (the state processor) removes the entity itself afterwards.
 */
final class EntityCascadeDeleter
{
    use DisablesTenantFilters;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function purgeChildrenOfTeam(Team $team): void
    {
        $clubId = $team->getClubId();
        $seasonId = $team->getSeasonId();
        $teamId = $team->getId();

        $this->withoutTenantFilters(function () use ($clubId, $seasonId, $teamId): void {
            $this->deleteByField(Reservation::class, 'teamId', $teamId, $clubId, $seasonId);
            $this->deleteByField(TeamCoach::class, 'teamId', $teamId, $clubId, $seasonId);
            $this->deleteByField(CoachPlayerMembership::class, 'teamId', $teamId, $clubId, $seasonId);
            // P1-4 PR C — habits + links follow their team (links on BOTH sides:
            // the couple is normalized, the team may sit on either column).
            $this->deleteByField(TeamMatchHabit::class, 'teamId', $teamId, $clubId, $seasonId);
            $this->deleteByField(TeamLink::class, 'teamAId', $teamId, $clubId, $seasonId);
            $this->deleteByField(TeamLink::class, 'teamBId', $teamId, $clubId, $seasonId);
            $this->deleteByField(ScheduleSlotTemplate::class, 'teamId', $teamId, $clubId, $seasonId);
            $this->deleteByField(ScheduleDiagnostic::class, 'teamId', $teamId, $clubId, $seasonId);
            // Match module: a team's fixtures + competition enrolments key on teamId.
            // NOTE: depuis la garde du périmètre engagé, cette ligne ne supprime plus
            // rien par l'API — un seul match, même UNPLACED, rend l'équipe indélébile
            // (TeamStateProcessor::cascadeBeforeDelete refuse AVANT d'arriver ici). On
            // la garde comme filet : si la règle d'engagement se relâche un jour, les
            // matchs partiront avec l'équipe au lieu de pendre sur un team_id mort.
            $this->deleteByField(Fixture::class, 'teamId', $teamId, $clubId, $seasonId);
            $this->deleteByField(Competition::class, 'teamId', $teamId, $clubId, $seasonId);
            // TeamTagAssignment has a season_id but NO club_id (scoped by season).
            $this->deleteByField(TeamTagAssignment::class, 'teamId', $teamId, null, $seasonId);
            // Period-editable structure (B1): a team's per-period overrides key on teamId.
            $this->deleteByField(TeamPeriodOverride::class, 'teamId', $teamId, $clubId, $seasonId);
            // #10 — sans équipe, une doléance n'a plus de sens : elle part.
            $this->deleteByField(CoachWish::class, 'teamId', $teamId, $clubId, $seasonId);
            $this->deleteScopedConstraint(ConstraintScope::TEAM, $teamId, $clubId, $seasonId);
            // P2-27 — la mutualisation : retirer l'équipe des groupes ; un groupe qui tombe
            // sous 2 membres n'a plus de sens et part avec ses lignes restantes.
            $this->pruneSharedTrainingGroupsForTeam($teamId, $clubId, $seasonId);
            $this->clearParentRef(Team::class, 'parentTeamId', $teamId, $clubId, $seasonId);
        });
    }

    public function purgeChildrenOfVenue(Venue $venue): void
    {
        $clubId = $venue->getClubId();
        $seasonId = $venue->getSeasonId();
        $venueId = $venue->getId();

        $this->withoutTenantFilters(function () use ($clubId, $seasonId, $venueId): void {
            $this->deleteByField(VenueTrainingSlot::class, 'venueId', $venueId, $clubId, $seasonId);
            $this->deleteByField(VenueMatchWindow::class, 'venueId', $venueId, $clubId, $seasonId);
            $this->deleteByField(VenueUnavailability::class, 'venueId', $venueId, $clubId, $seasonId);
            $this->deleteByField(VenuePeriodOverride::class, 'venueId', $venueId, $clubId, $seasonId);
            $this->deleteByField(Reservation::class, 'venueId', $venueId, $clubId, $seasonId);
            $this->deleteByField(ScheduleSlotTemplate::class, 'venueId', $venueId, $clubId, $seasonId);
            $this->deleteByField(ScheduleDiagnostic::class, 'venueId', $venueId, $clubId, $seasonId);
            $this->deleteScopedConstraint(ConstraintScope::FACILITY, $venueId, $clubId, $seasonId);
            $this->clearParentRef(Team::class, 'forcedVenueId', $venueId, $clubId, $seasonId);
            $this->clearParentRef(Venue::class, 'parentVenueId', $venueId, $clubId, $seasonId);
            // A fixture's venue is optional (match may be TBD) — the fixture
            // survives the venue delete, it just loses its (now-gone) venue.
            $this->clearParentRef(Fixture::class, 'venueId', $venueId, $clubId, $seasonId);
        });
    }

    public function purgeChildrenOfCoach(Coach $coach): void
    {
        $clubId = $coach->getClubId();
        $seasonId = $coach->getSeasonId();
        $coachId = $coach->getId();

        $this->withoutTenantFilters(function () use ($clubId, $seasonId, $coachId): void {
            $this->deleteByField(TeamCoach::class, 'coachId', $coachId, $clubId, $seasonId);
            $this->deleteByField(CoachPlayerMembership::class, 'coachId', $coachId, $clubId, $seasonId);
            $this->deleteByField(ScheduleDiagnostic::class, 'coachId', $coachId, $clubId, $seasonId);
            $this->deleteScopedConstraint(ConstraintScope::COACH, $coachId, $clubId, $seasonId);
            // A slot placement keeps existing without its (now-deleted) coach —
            // the engine leaves slot.coachId empty anyway, so null it out.
            $this->clearParentRef(ScheduleSlotTemplate::class, 'coachId', $coachId, $clubId, $seasonId);
            // #10 — la doléance SURVIT au coach supprimé, dé-attribuée : son info d'équipe
            // reste utile au plan de vacances.
            $this->clearParentRef(CoachWish::class, 'coachId', $coachId, $clubId, $seasonId);
            $this->clearParentRef(Coach::class, 'parentCoachId', $coachId, $clubId, $seasonId);
        });
    }

    /**
     * A slot is a leaf, but a Reservation pins to it LOGICALLY by (venueId,
     * dayOfWeek, startTime) — deleting the slot must clear those reservations
     * and their materialised HARD templates (same idiom as
     * ReservationStateProcessor), else they re-inject a pin on a slot that no
     * longer exists (the orphan-reservation bug this whole change fixes).
     */
    public function purgeChildrenOfSlot(VenueTrainingSlot $slot): void
    {
        $clubId = $slot->getClubId();
        $seasonId = $slot->getSeasonId();

        $this->withoutTenantFilters(function () use ($slot, $clubId, $seasonId): void {
            // Delete the reservations pinned to this slot, and ONLY the HARD
            // templates those reservations materialised (same idiom as
            // ReservationStateProcessor). Never the SOFT/NONE placements the
            // solver chose on that venue/day/time in already-generated
            // schedules — those are results, not pins, and belong to their
            // own schedule, not to this availability slot.
            $this->deleteBySlotKey(Reservation::class, $slot, $clubId, $seasonId, hardOnly: false);
            $this->deleteBySlotKey(ScheduleSlotTemplate::class, $slot, $clubId, $seasonId, hardOnly: true);
        });
    }

    /**
     * v2 cohérence canSplit — un gymnase repasse « indivisible » alors que des créneaux y
     * accueillent 2 équipes ou plus (état incohérent, cf. VenueStateProcessor). Sur CONFIRMATION
     * du gestionnaire, chaque créneau visé :
     *   - retombe à capacité 1 et perd son libellé de groupe (un libellé suppose ≥ 2 équipes,
     *     cf. VenueTrainingSlotStateProcessor::validateGroupLabel) ;
     *   - voit ses épinglages vidés — réservations ET les seuls verrous HARD qu'elles ont
     *     matérialisés, exactement les DEUX familles de {@see purgeChildrenOfSlot} (une réservation
     *     et son verrou sont les deux faces d'un même épinglage : en supprimer une sans l'autre est
     *     précisément le bug d'orphelin que ces méthodes existent pour fermer).
     *
     * Bornage par COUCHE assuré par `deleteBySlotKey` : chaque créneau porte son `schedulePlanId`,
     * donc un créneau de saison ne vide que les épinglages de base, un créneau de période que les
     * siens — les deux couches sont couvertes parce que l'appelant passe TOUS les créneaux ≥ 2 du
     * gymnase. Écrit en DQL comme le reste du service (hors UnitOfWork). Le planning est marqué
     * périmé par le write du gymnase lui-même (ResourceChangeStaleScheduleListener::venueTouched).
     *
     * @param list<VenueTrainingSlot> $slots les créneaux du gymnase à capacité ≥ 2
     */
    public function clampSplitSlotsAndClearPins(array $slots): void
    {
        $this->withoutTenantFilters(function () use ($slots): void {
            foreach ($slots as $slot) {
                $clubId = $slot->getClubId();
                $seasonId = $slot->getSeasonId();
                $this->deleteBySlotKey(Reservation::class, $slot, $clubId, $seasonId, hardOnly: false);
                $this->deleteBySlotKey(ScheduleSlotTemplate::class, $slot, $clubId, $seasonId, hardOnly: true);

                $this->entityManager->createQueryBuilder()
                    ->update(VenueTrainingSlot::class, 's')
                    ->set('s.capacity', ':one')
                    ->set('s.groupLabel', 'NULL')
                    ->where('s.id = :id')
                    ->setParameter('one', 1)
                    ->setParameter('id', $slot->getId())
                    ->getQuery()
                    ->execute();
            }
        });
    }

    /**
     * P4-44 — DÉPLACER un créneau déplace ses réservations, il ne les orpheline pas.
     *
     * Une `Reservation` désigne son créneau par le TRIPLET (gymnase, jour, heure),
     * jamais par son id : un `PUT` qui corrige l'horaire laissait donc la réservation
     * sur l'ancien triplet — un horaire qui n'existe plus. Le moteur ne s'en plaint
     * PAS sur le socle : il place la séance à l'horaire mort et rend `completed`
     * (mesuré). Le gestionnaire distribue un planning qui envoie ses équipes devant
     * une porte fermée.
     *
     * Le geste du gestionnaire est « ce créneau est en fait à 18h30 » — pas « annule
     * mes réservations ». On les SUIT donc (décision fondateur 2026-08-07), avec les
     * MÊMES bornes de couche que la purge ci-dessous : un créneau de saison ne touche
     * que les épinglages de base, un créneau de période que les siens.
     *
     * @param VenueTrainingSlot $before l'état AVANT modification (triplet d'origine)
     */
    public function moveChildrenOfSlot(VenueTrainingSlot $before, VenueTrainingSlot $after): void
    {
        if ($before->getVenueId() === $after->getVenueId()
            && $before->getDayOfWeek() === $after->getDayOfWeek()
            && $before->getStartTime()->format('H:i:s') === $after->getStartTime()->format('H:i:s')) {
            return; // le triplet n'a pas bougé : rien à suivre
        }

        $clubId = $before->getClubId();
        $seasonId = $before->getSeasonId();

        $this->withoutTenantFilters(function () use ($before, $after, $clubId, $seasonId): void {
            // Réservations ET verrous HARD qu'elles ont matérialisés — les mêmes deux
            // familles que `purgeChildrenOfSlot`, sinon le verrou resterait à l'ancien
            // horaire et `findBaseSlotTemplates` le ré-injecterait à chaque génération.
            $this->moveBySlotKey(Reservation::class, $before, $after, $clubId, $seasonId, hardOnly: false);
            $this->moveBySlotKey(ScheduleSlotTemplate::class, $before, $after, $clubId, $seasonId, hardOnly: true);
        });
    }

    /**
     * Les épinglages d'un créneau, identifiés par (gymnase, jour, heure) — ni une
     * réservation ni un verrou ne cite l'id du créneau.
     *
     * ⚠️ Bornés à la COUCHE du créneau supprimé (#8) : un créneau de SAISON emporte les
     * épinglages de base (schedulePlanId null), un créneau de PÉRIODE ceux de SA période.
     * Sans cette borne, vider la grille d'une période détruirait les réservations du
     * planning principal au même horaire — le planning principal n'est JAMAIS modifié par
     * une période (invariant fondateur n°1).
     */
    private function deleteBySlotKey(string $entityClass, VenueTrainingSlot $slot, string $clubId, string $seasonId, bool $hardOnly): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->delete($entityClass, 'e')
            ->where('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.venueId = :venueId')
            ->andWhere('e.dayOfWeek = :dayOfWeek')
            ->andWhere('e.startTime = :startTime')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->setParameter('venueId', $slot->getVenueId())
            ->setParameter('dayOfWeek', $slot->getDayOfWeek())
            ->setParameter('startTime', $slot->getStartTime());
        $planId = $slot->getSchedulePlanId();
        if (Reservation::class === $entityClass) {
            // La réservation porte son plan : borne EXACTE. Un créneau de saison n'emporte
            // que les réservations de base, un créneau de période que les siennes.
            $qb->andWhere(null === $planId ? 'e.schedulePlanId IS NULL' : 'e.schedulePlanId = :planId');
            if (null !== $planId) {
                $qb->setParameter('planId', $planId);
            }
        } elseif (ScheduleSlotTemplate::class === $entityClass) {
            // Le verrou ne porte que sa VERSION, jamais un plan : on borne donc aux
            // versions de la COUCHE du créneau supprimé — celles de CE plan pour un
            // créneau de période, celles du plan de SAISON pour un créneau de socle.
            //
            // La borne va dans les DEUX SENS depuis #8, et c'est nécessaire : une période
            // possède désormais sa grille, une copie. Supprimer un créneau de saison ne
            // supprime PAS la copie qu'en détient la période — emporter au passage le
            // verrou que le gestionnaire y avait posé laissait la période avec un créneau
            // toujours offert mais son épinglage disparu, sans un mot.
            $scheduleIds = array_map(
                static fn (Schedule $s): string => $s->getId(),
                $this->entityManager->getRepository(Schedule::class)->findBy(['schedulePlanId' => $planId ?? $this->seasonPlanIds($seasonId)]),
            );
            if ([] === $scheduleIds) {
                return; // aucune version sur cette couche : aucun verrou à emporter
            }
            $qb->andWhere('e.scheduleId IN (:scheduleIds)')->setParameter('scheduleIds', $scheduleIds);
        }
        if ($hardOnly) {
            $qb->andWhere('e.lockLevel = :hard')->setParameter('hard', LockLevel::HARD);
        }
        $qb->getQuery()->execute();
    }

    /**
     * Le jumeau UPDATE de {@see deleteBySlotKey} : MÊME sélection (mêmes bornes de
     * couche, même famille), mais on réécrit le triplet au lieu de supprimer la ligne.
     * Écrit en DQL comme son jumeau : ces lignes ne passent pas par l'UnitOfWork, et
     * un `find`+`set` sur des centaines d'épinglages coûterait sans rien apporter.
     */
    private function moveBySlotKey(string $entityClass, VenueTrainingSlot $before, VenueTrainingSlot $after, string $clubId, string $seasonId, bool $hardOnly): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->update($entityClass, 'e')
            ->set('e.venueId', ':newVenueId')
            ->set('e.dayOfWeek', ':newDayOfWeek')
            ->set('e.startTime', ':newStartTime')
            ->where('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.venueId = :venueId')
            ->andWhere('e.dayOfWeek = :dayOfWeek')
            ->andWhere('e.startTime = :startTime')
            ->setParameter('newVenueId', $after->getVenueId())
            ->setParameter('newDayOfWeek', $after->getDayOfWeek())
            ->setParameter('newStartTime', $after->getStartTime())
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->setParameter('venueId', $before->getVenueId())
            ->setParameter('dayOfWeek', $before->getDayOfWeek())
            ->setParameter('startTime', $before->getStartTime());

        $planId = $before->getSchedulePlanId();
        if (Reservation::class === $entityClass) {
            $qb->andWhere(null === $planId ? 'e.schedulePlanId IS NULL' : 'e.schedulePlanId = :planId');
            if (null !== $planId) {
                $qb->setParameter('planId', $planId);
            }
        } elseif (ScheduleSlotTemplate::class === $entityClass) {
            $scheduleIds = array_map(
                static fn (Schedule $s): string => $s->getId(),
                $this->entityManager->getRepository(Schedule::class)->findBy(['schedulePlanId' => $planId ?? $this->seasonPlanIds($seasonId)]),
            );
            if ([] === $scheduleIds) {
                return;
            }
            $qb->andWhere('e.scheduleId IN (:scheduleIds)')->setParameter('scheduleIds', $scheduleIds);
        }
        if ($hardOnly) {
            $qb->andWhere('e.lockLevel = :hard')->setParameter('hard', LockLevel::HARD);
        }
        $qb->getQuery()->execute();
    }

    /**
     * Les plans SOCLE de la saison — la couche d'un créneau saisonnier (schedulePlanId
     * null). Une liste, pas un id : rien n'interdit en base d'en avoir plus d'un, et un
     * `find` unique choisirait silencieusement.
     *
     * @return list<string>
     */
    private function seasonPlanIds(string $seasonId): array
    {
        return array_map(
            static fn (SchedulePlan $p): string => $p->getId(),
            $this->entityManager->getRepository(SchedulePlan::class)->findBy(['seasonId' => $seasonId, 'type' => SchedulePlanType::SEASON]),
        );
    }

    /**
     * P2-27 — retire une équipe supprimée de tous ses groupes de mutualisation, puis élague les
     * groupes tombés sous 2 membres (le moteur exige ≥ 2 : un groupe à 1 est mort). Les lignes
     * membres restantes du groupe élagué partent avec lui — aucun orphelin.
     */
    private function pruneSharedTrainingGroupsForTeam(string $teamId, string $clubId, string $seasonId): void
    {
        /** @var list<string> $affectedGroupIds */
        $affectedGroupIds = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT t.groupId')
            ->from(SharedTrainingGroupTeam::class, 't')
            ->where('t.teamId = :teamId')
            ->andWhere('t.clubId = :clubId')
            ->andWhere('t.seasonId = :seasonId')
            ->setParameter('teamId', $teamId)
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $affectedGroupIds) {
            return;
        }

        $this->deleteByField(SharedTrainingGroupTeam::class, 'teamId', $teamId, $clubId, $seasonId);

        foreach ($affectedGroupIds as $groupId) {
            $remaining = (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(t.id)')
                ->from(SharedTrainingGroupTeam::class, 't')
                ->where('t.groupId = :groupId')
                ->setParameter('groupId', $groupId)
                ->getQuery()
                ->getSingleScalarResult();
            if ($remaining >= 2) {
                continue;
            }
            $this->entityManager->createQueryBuilder()
                ->delete(SharedTrainingGroupTeam::class, 't')
                ->where('t.groupId = :groupId')
                ->setParameter('groupId', $groupId)
                ->getQuery()
                ->execute();
            $this->entityManager->createQueryBuilder()
                ->delete(SharedTrainingGroup::class, 'g')
                ->where('g.id = :groupId')
                ->setParameter('groupId', $groupId)
                ->getQuery()
                ->execute();
        }
    }

    private function deleteByField(string $entityClass, string $field, string $value, ?string $clubId, string $seasonId): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->delete($entityClass, 'e')
            ->where(\sprintf('e.%s = :value', $field))
            ->andWhere('e.seasonId = :seasonId')
            ->setParameter('value', $value)
            ->setParameter('seasonId', $seasonId);
        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }
        $qb->getQuery()->execute();
    }

    private function deleteScopedConstraint(ConstraintScope $scope, string $targetId, string $clubId, string $seasonId): void
    {
        // Period toggles keyed on these constraints must go first, else deleting a
        // team/coach/venue whose permanent scoped constraint had a period override
        // leaves that override dangling (same no-orphan contract as the direct
        // constraint delete in ConstraintStateProcessor::cascadeBeforeDelete).
        $constraintIds = $this->entityManager->createQueryBuilder()
            ->select('e.id')
            ->from(Constraint::class, 'e')
            ->where('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.scope = :scope')
            ->andWhere('e.scopeTargetId = :target')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->setParameter('scope', $scope)
            ->setParameter('target', $targetId)
            ->getQuery()
            ->getSingleColumnResult();
        if ([] !== $constraintIds) {
            $this->entityManager->createQueryBuilder()
                ->delete(ConstraintPeriodOverride::class, 'o')
                ->where('o.constraintId IN (:ids)')
                ->setParameter('ids', $constraintIds)
                ->getQuery()
                ->execute();
        }

        $this->entityManager->createQueryBuilder()
            ->delete(Constraint::class, 'e')
            ->where('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.scope = :scope')
            ->andWhere('e.scopeTargetId = :target')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->setParameter('scope', $scope)
            ->setParameter('target', $targetId)
            ->getQuery()
            ->execute();
    }

    /** Null out a (self- or cross-) reference column pointing at the deleted id. */
    private function clearParentRef(string $entityClass, string $field, string $value, string $clubId, string $seasonId): void
    {
        $this->entityManager->createQueryBuilder()
            ->update($entityClass, 'e')
            ->set(\sprintf('e.%s', $field), 'NULL')
            ->where(\sprintf('e.%s = :value', $field))
            ->andWhere('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->setParameter('value', $value)
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->execute();
    }

    private function withoutTenantFilters(callable $work): void
    {
        $this->disableTenantFilters($this->entityManager);
        $work();
    }
}
