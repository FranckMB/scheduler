<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\ScheduleSlotTemplate;
use App\Repository\CalendarEntryRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Loads the tenant-scoped context EffectiveScheduleResolver::resolve() needs:
 * the season's chosen calendar, the ordered active periods with their overlay
 * (chosen version of their plan, else null = no training), and the slots of
 * every involved schedule. One home — the conflicts radar and the
 * venue-unavailability impact must read the SAME picture (P1-4 PR B).
 *
 * Repositories go through the Doctrine club+season filters: the context is
 * always the caller's club.
 */
final class TrainingCalendarContext
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CalendarEntryRepository $calendarEntryRepository,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /**
     * @return array{
     *     seasonScheduleId: string|null,
     *     activePeriods: list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}>,
     *     slotsBySchedule: array<string, list<ScheduleSlotTemplate>>,
     * }
     */
    public function load(?string $seasonId): array
    {
        // ADR-0002 : le calendrier de base = la version CHOISIE du plan SEASON.
        // null = espace de travail : rien n'est arrêté.
        $seasonScheduleId = $this->schedulePlanProvisioner->chosenOfSeasonPlan($seasonId);

        // Active period entries capture the dates they cover (ordered so
        // overlapping periods resolve deterministically); overlay = the chosen
        // version of the period's plan, resolved in ONE query for all periods.
        $activePeriods = [];
        $scheduleIds = null !== $seasonScheduleId ? [$seasonScheduleId] : [];
        $periods = $this->calendarEntryRepository->findActivePeriodsOrdered();
        $overlayByEntry = $this->schedulePlanProvisioner->chosenByPeriodPlans(
            array_map(static fn (CalendarEntry $p): string => $p->getId(), $periods),
        );
        foreach ($periods as $period) {
            $overlayId = $overlayByEntry[$period->getId()] ?? null;
            $activePeriods[] = [
                'start' => $period->getStartDate(),
                'end' => $period->getEndDate(),
                'scheduleId' => $overlayId,
            ];
            if (null !== $overlayId) {
                $scheduleIds[] = $overlayId;
            }
        }

        $slotsBySchedule = [];
        if ([] !== $scheduleIds) {
            /** @var list<ScheduleSlotTemplate> $slots */
            $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
                'scheduleId' => array_values(array_unique($scheduleIds)),
            ]);
            foreach ($slots as $slot) {
                $slotsBySchedule[$slot->getScheduleId()][] = $slot;
            }
        }

        return [
            'seasonScheduleId' => $seasonScheduleId,
            'activePeriods' => $activePeriods,
            'slotsBySchedule' => $slotsBySchedule,
        ];
    }
}
