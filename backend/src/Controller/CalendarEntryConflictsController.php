<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Enum\CalendarEntryKind;
use App\Enum\ConstraintFamily;
use DateInterval;
use DatePeriod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Palier A: sessions the base plan wants to place in a venue that a period marks
 * as closed — "séances à replacer" for the cockpit calendar/radar. Nothing is
 * moved (overlay generation is palier B); this only surfaces the conflict.
 *
 * For a period entry, the closed venues are read from its dated FACILITY
 * constraints (Constraint.calendarEntryId), then the baseline schedule's slot
 * templates on those venues whose weekday falls inside the period window are
 * returned with the concrete conflicting dates.
 * See accueil-cockpit-temporel.md §6 (état intermédiaire, palier A).
 */
final class CalendarEntryConflictsController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {}

    #[Route('/api/calendar-entries/{id}/conflicts', name: 'api_calendar_entry_conflicts', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $currentClubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $entry = $this->entityManager->getRepository(CalendarEntry::class)->find($id);
        if (!$entry instanceof CalendarEntry) {
            return $this->json(['error' => 'Calendar entry not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($entry->getClubId() !== $currentClubId) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        if (CalendarEntryKind::PERIOD !== $entry->getKind()) {
            return $this->json(['entryId' => $entry->getId(), 'venueIds' => [], 'conflicts' => []]);
        }

        // Closed venues = active FACILITY constraints attached to this entry.
        /** @var list<Constraint> $facilityConstraints */
        $facilityConstraints = $this->entityManager->getRepository(Constraint::class)->findBy([
            'calendarEntryId' => $entry->getId(),
            'family' => ConstraintFamily::FACILITY,
            'isActive' => true,
        ]);
        $venueIds = array_values(array_unique(array_filter(
            array_map(static fn (Constraint $c): ?string => $c->getScopeTargetId(), $facilityConstraints),
        )));

        if ([] === $venueIds) {
            return $this->json(['entryId' => $entry->getId(), 'venueIds' => [], 'conflicts' => []]);
        }

        // The entry's OWN season baseline (not the active season) — an entry may
        // belong to a past/other season; scoring it against a different season's
        // plan would be wrong.
        $season = $this->entityManager->find(Season::class, $entry->getSeasonId());
        $baselineId = $season?->getBaselineScheduleId();
        if (null === $baselineId) {
            return $this->json(['entryId' => $entry->getId(), 'venueIds' => $venueIds, 'conflicts' => []]);
        }

        /** @var list<ScheduleSlotTemplate> $slots */
        $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
            'scheduleId' => $baselineId,
            'venueId' => $venueIds,
        ]);

        // Concrete dates of the window, indexed by ISO weekday (1=Mon..7=Sun) —
        // ScheduleSlotTemplate.dayOfWeek uses the same ISO convention.
        $datesByWeekday = $this->windowDatesByWeekday($entry);

        $conflicts = [];
        foreach ($slots as $slot) {
            $dates = $datesByWeekday[$slot->getDayOfWeek()] ?? [];
            if ([] === $dates) {
                continue;
            }
            $conflicts[] = [
                'slotTemplateId' => $slot->getId(),
                'teamId' => $slot->getTeamId(),
                'venueId' => $slot->getVenueId(),
                'dayOfWeek' => $slot->getDayOfWeek(),
                'startTime' => $slot->getStartTime()->format('H:i'),
                'endTime' => $slot->getStartTime()->add(new DateInterval('PT' . $slot->getDurationMinutes() . 'M'))->format('H:i'),
                'dates' => $dates,
            ];
        }

        return $this->json([
            'entryId' => $entry->getId(),
            'venueIds' => $venueIds,
            'conflicts' => $conflicts,
        ]);
    }

    /**
     * @return array<int, list<string>> ISO weekday (1..7) → list of Y-m-d dates in the window
     */
    private function windowDatesByWeekday(CalendarEntry $entry): array
    {
        $end = $entry->getEndDate()->modify('+1 day'); // DatePeriod end is exclusive
        $byWeekday = [];
        foreach (new DatePeriod($entry->getStartDate(), new DateInterval('P1D'), $end) as $day) {
            $byWeekday[(int) $day->format('N')][] = $day->format('Y-m-d');
        }

        return $byWeekday;
    }
}
