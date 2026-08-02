<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Fixture;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\VenueUnavailability;
use DateInterval;
use DateTimeImmutable;

/**
 * What a venue unavailability actually AFFECTS (cadrage P1-4 §5.2) — the
 * material of the cockpit alert card: « Armand indisponible du 4 au 28 févr.
 * (travaux) — 6 créneaux d'entraînement/sem. et 3 matchs placés concernés ».
 *
 * Alert only: this computes and reports, it never blocks nor regenerates —
 * the manager decides (asks the city hall, requests a derogation…).
 *
 * Pure/stateless. Training sessions are counted by projecting the weekly slots
 * of the schedule EFFECTIVE on each date of the range ({@see
 * EffectiveScheduleResolver} — same ADR-0002 rules as the match radar: an
 * active period captures its dates; a period plan without a chosen version
 * means NO training, so no phantom alert).
 */
final class VenueUnavailabilityImpact
{
    public function __construct(private readonly EffectiveScheduleResolver $effectiveScheduleResolver) {}

    /**
     * @param list<VenueUnavailability>                                                              $unavailabilities scoped
     * @param list<Fixture>                                                                          $fixtures         scoped
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}> $activePeriods
     * @param array<string, list<ScheduleSlotTemplate>>                                              $slotsBySchedule
     *
     * @return list<array<string, mixed>> one impact item per unavailability
     */
    public function build(
        array $unavailabilities,
        array $fixtures,
        ?string $seasonScheduleId,
        array $activePeriods,
        array $slotsBySchedule,
    ): array {
        $items = [];
        foreach ($unavailabilities as $unavailability) {
            $items[] = $this->impactOf($unavailability, $fixtures, $seasonScheduleId, $activePeriods, $slotsBySchedule);
        }

        return $items;
    }

    /**
     * @param list<Fixture>                                                                          $fixtures
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}> $activePeriods
     * @param array<string, list<ScheduleSlotTemplate>>                                              $slotsBySchedule
     *
     * @return array<string, mixed>
     */
    private function impactOf(
        VenueUnavailability $unavailability,
        array $fixtures,
        ?string $seasonScheduleId,
        array $activePeriods,
        array $slotsBySchedule,
    ): array {
        $venueId = $unavailability->getVenueId();
        $from = $unavailability->getStartDate()->format('Y-m-d');
        $until = $unavailability->getEndDate()->format('Y-m-d');

        // Matches sitting on the venue inside the range (inclusive bounds).
        $affectedFixtures = [];
        foreach ($fixtures as $fixture) {
            if ($fixture->getVenueId() !== $venueId) {
                continue;
            }
            $date = $fixture->getMatchDate()->format('Y-m-d');
            if ($date < $from || $date > $until) {
                continue;
            }
            $affectedFixtures[] = [
                'fixtureId' => $fixture->getId(),
                'teamId' => $fixture->getTeamId(),
                'matchDate' => $date,
                'kickoffTime' => $fixture->getKickoffTime()?->format('H:i'),
                'status' => $fixture->getStatus()->value,
            ];
        }

        // Training occurrences: walk each date of the range, resolve the
        // effective schedule, count that venue's slots on that weekday.
        $occurrences = 0;
        $affectedSlotIds = [];
        $cursor = $unavailability->getStartDate();
        $end = $unavailability->getEndDate();
        while ($cursor <= $end) {
            $scheduleId = $this->effectiveScheduleResolver->resolve($cursor, $activePeriods, $seasonScheduleId);
            if (null !== $scheduleId) {
                $isoWeekday = (int) $cursor->format('N');
                foreach ($slotsBySchedule[$scheduleId] ?? [] as $slot) {
                    if ($slot->getVenueId() === $venueId && $slot->getDayOfWeek() === $isoWeekday) {
                        ++$occurrences;
                        $affectedSlotIds[$slot->getId()] = true;
                    }
                }
            }
            $cursor = $cursor->add(new DateInterval('P1D'));
        }

        return [
            'unavailabilityId' => $unavailability->getId(),
            'venueId' => $venueId,
            'startDate' => $from,
            'endDate' => $until,
            'label' => $unavailability->getLabel(),
            'affectedFixtures' => $affectedFixtures,
            'trainingOccurrences' => $occurrences,
            'trainingSlotCount' => \count($affectedSlotIds),
        ];
    }
}
