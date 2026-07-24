<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalendarEntry;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarEntry>
 */
final class CalendarEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarEntry::class);
    }

    /**
     * @return list<CalendarEntry>
     */
    public function findByClubSeason(string $clubId, string $seasonId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Les périodes de la saison PORTANT UN PLAN et pas encore échues — ce que déplacer
     * le calendrier de base détruit (ADR-0002 inv. 14, décision fondateur 2026-07-24).
     *
     * Volontairement PAS keyée sur `chosenScheduleId IS NOT NULL` : depuis #8 le plan
     * naît du geste « Adapter » et possède aussitôt sa grille (copie du modèle de
     * saison). Ne retenir que les périodes validées laissait vivre le plan et la grille
     * copiée d'une période jamais générée, adossés à un socle qui n'existe plus, sans
     * que le gestionnaire en soit averti ni ne puisse les voir.
     *
     * `endDate >= today` : un planning déjà joué est de l'histoire, le nouveau socle ne
     * le concerne pas. Une période EN COURS n'est pas échue — elle est reprise.
     *
     * @return list<CalendarEntry>
     */
    public function findWithPlanNotEnded(string $clubId, string $seasonId, DateTimeImmutable $today): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.endDate >= :today')
            ->andWhere('EXISTS (SELECT p.id FROM App\Entity\SchedulePlan p WHERE p.calendarEntryId = e.id)')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->setParameter('today', $today->format('Y-m-d'))
            ->orderBy('e.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Period entries of a club whose window already ended (endDate < today) — the
     * overlay-purge scope. Explicit clubId (no ambient season filter in CLI); the
     * caller purges every overlay version of each returned entry.
     *
     * @return list<CalendarEntry>
     */
    public function findEndedPeriods(string $clubId, DateTimeImmutable $today): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.clubId = :clubId')
            ->andWhere('e.kind = :kind')
            ->andWhere('e.endDate < :today')
            ->setParameter('clubId', $clubId)
            ->setParameter('kind', \App\Enum\CalendarEntryKind::PERIOD)
            ->setParameter('today', $today->format('Y-m-d'))
            ->orderBy('e.endDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Active period entries of the current tenant scope, ordered deterministically
     * (startDate, then id) so a date covered by two periods always resolves to the
     * same one. Relies on the ambient club+season Doctrine filters for scoping.
     * A period "captures" the dates it covers: within it the base plan does not
     * apply — its overlay if any, else no training plan at all (a closure means
     * "no training", cf. findUpcomingPeriodsWithoutOverlay).
     *
     * @return list<CalendarEntry>
     */
    public function findActivePeriodsOrdered(): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.kind = :kind')
            ->andWhere('e.status = :status')
            ->setParameter('kind', \App\Enum\CalendarEntryKind::PERIOD)
            ->setParameter('status', \App\Enum\CalendarEntryStatus::ACTIVE)
            ->orderBy('e.startDate', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Active period entries of the season that still have NO overlay plan and
     * start within [$today, $today + $horizonDays] — the reminder cron's horizon.
     * A window (not an exact date) so a missed daily run still catches the period
     * on the next run; the cron then picks the milestone bucket + dedups per
     * (entry, threshold). Only kind=PERIOD, status=ACTIVE carry an overlay.
     *
     * @return list<CalendarEntry>
     */
    public function findUpcomingPeriodsWithoutOverlay(string $clubId, string $seasonId, DateTimeImmutable $today, int $horizonDays): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.kind = :kind')
            ->andWhere('e.status = :status')
            // ADR-0002 lot D-b : « pas encore d'overlay » = le plan de période n'a AUCUNE
            // version (schedule_plan.calendarEntryId → schedule.schedulePlanId), plus un
            // pointeur sur l'entrée. Le rappel s'arrête dès la 1re génération, comme avant.
            ->andWhere('NOT EXISTS (SELECT s.id FROM App\Entity\Schedule s, App\Entity\SchedulePlan p WHERE p.calendarEntryId = e.id AND s.schedulePlanId = p.id)')
            // P2-5 E1 : une mère DÉCOUPÉE en semaines ne porte jamais de version sur
            // son plan bloc (exclusivité) — la rappeler serait un faux positif menant
            // au 409. Ses SEMAINES, elles, sont des périodes à part entière et portent
            // leurs propres rappels si elles restent sans version (revue #262 round 2).
            ->andWhere('NOT EXISTS (SELECT c.id FROM App\Entity\CalendarEntry c WHERE c.parentEntryId = e.id)')
            // Only overlay-capable period types: reminding about a cutoff/custom/
            // mutualisation period would CTA into a 422 (overlay creation refuses
            // them) — a cutoff means "no training", there is no plan to prepare.
            ->andWhere('e.periodType IN (:generatingTypes)')
            ->andWhere('e.startDate >= :from')
            ->andWhere('e.startDate <= :to')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->setParameter('kind', \App\Enum\CalendarEntryKind::PERIOD)
            ->setParameter('status', \App\Enum\CalendarEntryStatus::ACTIVE)
            ->setParameter('generatingTypes', [\App\Enum\CalendarEntryPeriodType::CLOSURE, \App\Enum\CalendarEntryPeriodType::HOLIDAY])
            ->setParameter('from', $today->format('Y-m-d'))
            ->setParameter('to', $today->modify(\sprintf('+%d days', $horizonDays))->format('Y-m-d'))
            ->orderBy('e.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
