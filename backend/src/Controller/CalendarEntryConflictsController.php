<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryStatus;
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
        private readonly \App\Service\SchedulePlanProvisioner $schedulePlanProvisioner,
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

        // Le pointeur est lu UNE fois, avant toute sortie : `seasonPlanChosen` ne doit
        // jamais être affirmé sans l'avoir consulté. Codé en dur à `true` sur les
        // sorties courtes, il affirmait un fait non vérifié — et le radar, qui masque
        // désormais les fermetures sans impact, faisait disparaître une période sur ce
        // mensonge.
        $seasonScheduleId = $this->schedulePlanProvisioner->chosenOfSeasonPlan($entry->getSeasonId());
        $planChosen = null !== $seasonScheduleId;

        // An IGNORED entry was explicitly dismissed by the manager: it must not
        // keep raising conflicts (the radar would resurrect it as a to-do).
        if (CalendarEntryKind::PERIOD !== $entry->getKind() || CalendarEntryStatus::IGNORED === $entry->getStatus()) {
            return $this->json(['entryId' => $entry->getId(), 'venueIds' => [], 'conflicts' => [], 'seasonPlanChosen' => $planChosen]);
        }

        // Closed venues = active FACILITY constraints attached to this entry.
        /** @var list<Constraint> $facilityConstraints */
        // P2-5 E1 : les datées d'une semaine ENFANT vivent sur sa période MÈRE.
        $facilityConstraints = $this->entityManager->getRepository(Constraint::class)->findBy([
            'calendarEntryId' => $entry->getParentEntryId() ?? $entry->getId(),
            'family' => ConstraintFamily::FACILITY,
            'isActive' => true,
        ]);
        $venueIds = array_values(array_unique(array_filter(
            array_map(static fn (Constraint $c): ?string => $c->getScopeTargetId(), $facilityConstraints),
        )));

        if ([] === $venueIds) {
            return $this->json(['entryId' => $entry->getId(), 'venueIds' => [], 'conflicts' => [], 'seasonPlanChosen' => $planChosen]);
        }

        // The entry's OWN season baseline (not the active season) — an entry may
        // belong to a past/other season; scoring it against a different season's
        // plan would be wrong.
        // ADR-0002 : le calendrier de base = la version CHOISIE du plan SEASON (lue plus
        // haut). Rien ne la pose automatiquement — tant que le gestionnaire n'a pas
        // validé, la saison n'a PAS de calendrier et le radar n'a rien à comparer.
        //
        // `seasonPlanChosen: false` dit exactement ça. Rendre `conflicts: []` sans le
        // dire se lisait « aucun impact » : le gestionnaire déclarait une fermeture de
        // gymnase, lisait que tout allait bien, et n'adaptait rien — alors que le radar
        // n'avait simplement rien regardé. Un silence qui ment est pire qu'un blanc.
        if (null === $seasonScheduleId) {
            return $this->json(['entryId' => $entry->getId(), 'venueIds' => $venueIds, 'conflicts' => [], 'seasonPlanChosen' => false]);
        }

        /** @var list<ScheduleSlotTemplate> $slots */
        $slots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
            'scheduleId' => $seasonScheduleId,
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
            'seasonPlanChosen' => $planChosen,
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
