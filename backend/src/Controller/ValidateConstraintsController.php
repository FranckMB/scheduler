<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Enum\CalendarEntryPeriodType;
use App\Repository\CalendarEntryRepository;
use App\Repository\ConstraintRepository;
use App\Repository\TeamRepository;
use App\Repository\VenueRepository;
use App\Service\CoachDoubleBookingDetector;
use App\Service\ConstraintValidationService;
use App\Service\ManagementAccessGuard;
use App\Service\PeriodConstraintSelection;
use App\Service\PeriodConstraintSelector;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * BW3 — pre-solve gate. Runs the (previously unwired) ConstraintValidationService
 * over the club's constraints so the wizard can flag gross errors (a coach who
 * "starts after he ends", contradictory HARD rules…) BEFORE generating.
 */
final class ValidateConstraintsController extends AbstractController
{
    public function __construct(
        private readonly ConstraintRepository $constraintRepository,
        private readonly CalendarEntryRepository $calendarEntryRepository,
        private readonly SeasonResolver $seasonResolver,
        private readonly ConstraintValidationService $validationService,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly TeamRepository $teamRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly VenueRepository $venueRepository,
        private readonly CoachDoubleBookingDetector $coachDoubleBookingDetector,
        private readonly PeriodConstraintSelector $periodConstraintSelector,
    ) {}

    #[Route('/api/constraints/validate', name: 'api_constraints_validate', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        // SEC-12: the pre-solve gate is a management action (part of the cockpit /
        // generation flow) — align it with the rest of the cockpit's role gate.
        $this->managementAccessGuard->assertManager();

        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');
        if (!\is_string($clubId) || '' === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $seasonId = $this->seasonResolver->selectedOrCurrent($request, $clubId)?->getId();
        if (null === $seasonId) {
            return $this->json(['error' => 'No active season.'], Response::HTTP_BAD_REQUEST);
        }

        $warnings = [];
        $selection = null;
        $calendarEntryId = $this->requestedCalendarEntryId($request);
        if (null !== $calendarEntryId) {
            $calendarEntry = $this->calendarEntryRepository->find($calendarEntryId);
            if (!$calendarEntry instanceof CalendarEntry) {
                return $this->json(['error' => 'No active period.'], Response::HTTP_BAD_REQUEST);
            }

            ['constraints' => $constraints, 'warnings' => $warnings, 'selection' => $selection] = $this->constraintsForPeriod($clubId, $seasonId, $calendarEntry);
        } else {
            /** @var list<Constraint> $constraints */
            $constraints = $this->constraintRepository->findPermanentByClubSeason($clubId, $seasonId);
        }

        // Map teamId → sessions/week for the fail-fast venue-minimum check — only
        // loaded when at least one constraint actually carries a minAtVenueId (the
        // vast majority of validations are TIME/DAY/COACH and never need it).
        $teamSessions = [];
        $needsTeams = array_filter($constraints, static fn (Constraint $c): bool => isset($c->getConfig()['minAtVenueId']));
        if ([] !== $needsTeams) {
            foreach ($this->teamRepository->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $team) {
                $teamSessions[$team->getId()] = $team->getSessionsPerWeek();
            }
        }

        $errors = [];
        foreach ($constraints as $constraint) {
            $messages = $this->validationService->validate($constraint);
            $venueMinError = $this->validationService->venueMinimumError($constraint, $teamSessions[$constraint->getScopeTargetId()] ?? null);
            if (null !== $venueMinError) {
                $messages[] = $venueMinError;
            }
            if ([] !== $messages) {
                $errors[$constraint->getId()] = $messages;
            }
        }

        $conflicts = array_map(
            static fn (array $c): array => [
                'constraint1Id' => $c['constraint1']->getId(),
                'constraint2Id' => $c['constraint2']->getId(),
                'reason' => $c['reason'],
            ],
            $this->validationService->detectConflicts($constraints),
        );

        // P2-9 PR B — IMPOSSIBILITÉ PHYSIQUE : un verrou qui met un coach à deux endroits
        // en même temps. Le solveur ne peut PAS l'attraper (un verrou HARD est pré-placé
        // hors modèle, ALIGN-07), et ce n'est pas une préférence bafouée — le gestionnaire
        // n'a jamais choisi que son coach se dédouble. Décision fondateur : ça BLOQUE.
        $blockers = array_map(
            fn (array $conflict): string => $this->coachDoubleBookingDetector->describeForRecap($conflict),
            $this->coachDoubleBookingDetector->detect($this->reservationsInScope($clubId, $seasonId, $calendarEntryId, $selection), $clubId, $seasonId),
        );

        // #8 (fondateur 2026-07-24) — un avertissement n'invalide RIEN : « SM1 va ailleurs,
        // on ignore la contrainte, mais on AVERTIT ». `valid` et le code HTTP restent
        // calculés sur les seules erreurs et conflits… et désormais les bloqueurs, qui
        // sont l'exact contraire d'un avertissement.
        $valid = [] === $errors && [] === $conflicts && [] === $blockers;

        return $this->json(
            ['valid' => $valid, 'errors' => $errors, 'conflicts' => $conflicts, 'warnings' => $warnings, 'blockers' => $blockers],
            $valid ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    private function requestedCalendarEntryId(?Request $request): ?string
    {
        $content = $request?->getContent();
        if (!\is_string($content) || '' === $content) {
            return null;
        }
        $data = json_decode($content, true);
        $id = \is_array($data) ? ($data['calendarEntryId'] ?? null) : null;

        return \is_string($id) && '' !== $id ? $id : null;
    }

    /**
     * P2-14 — le gate ne recopie plus la sélection : il la DEMANDE à la source unique
     * (`PeriodConstraintSelector`), la même que `buildForPeriodPlan`. Ce qui reste ici est
     * l'habillage : transformer les drops en messages pour le gestionnaire.
     *
     * @return array{constraints: list<Constraint>, warnings: list<string>, selection: PeriodConstraintSelection|null}
     */
    private function constraintsForPeriod(string $clubId, string $seasonId, CalendarEntry $calendarEntry): array
    {
        $periodType = $calendarEntry->getPeriodType();
        if (!\in_array($periodType, [CalendarEntryPeriodType::CLOSURE, CalendarEntryPeriodType::HOLIDAY], true)) {
            // Période non génératrice : seules ses datées existent, aucun réglage de plan.
            /** @var list<Constraint> $dated */
            $dated = $this->constraintRepository->findBy(['calendarEntryId' => $calendarEntry->datedConstraintSourceId(), 'clubId' => $clubId]);

            return ['constraints' => $dated, 'warnings' => [], 'selection' => null];
        }

        // Les réglages de la période pendent au PLAN (inv. 5, lot C2). Une période
        // génératrice en a toujours un (il naît du geste, lot C1) ; un null ne peut venir
        // que d'une donnée antérieure au lot — sans réglage à appliquer, le récap reste juste.
        $schedulePlanId = $this->schedulePlanProvisioner->periodPlanId($calendarEntry->getId());
        if (null === $schedulePlanId) {
            /** @var list<Constraint> $dated */
            $dated = $this->constraintRepository->findBy(['calendarEntryId' => $calendarEntry->datedConstraintSourceId(), 'clubId' => $clubId]);

            return ['constraints' => $dated, 'warnings' => [], 'selection' => null];
        }

        $selection = $this->periodConstraintSelector->selectForPeriodPlan($clubId, $seasonId, $schedulePlanId, $calendarEntry);

        // Une contrainte sortie pour gymnase désactivé ne partira pas au solveur : elle
        // sort donc aussi du gate (sinon le récap valide un jeu que le payload n'aura
        // pas), et le dirigeant en est AVERTI plutôt que de la voir disparaître en silence.
        $venueIds = array_values(array_unique(array_map(static fn (array $drop): string => $drop['venueId'], $selection->droppedForDisabledVenue)));
        $venueNames = $this->venueNames($venueIds);
        $warnings = array_map(
            static fn (array $drop): string => \sprintf(
                '« %s » vise le gymnase %s, désactivé pour cette période : elle ne sera pas appliquée.',
                $drop['constraint']->getName(),
                $venueNames[$drop['venueId']] ?? $drop['venueId'],
            ),
            $selection->droppedForDisabledVenue,
        );

        return ['constraints' => $selection->kept, 'warnings' => $warnings, 'selection' => $selection];
    }

    /**
     * Les réservations qui partiront RÉELLEMENT au solveur — le même périmètre que
     * `ScheduleConstraintBuilder`, jamais un autre : le socle ne lit que les siennes
     * (`schedulePlanId IS NULL`) et une période que les siennes, gymnases désactivés
     * retirés. Mélanger les deux inventerait des conflits entre mondes disjoints ;
     * bloquer sur une réservation qui ne part pas au solveur enfermerait le
     * gestionnaire sur un problème qui n'existe pas.
     *
     * @return list<Reservation>
     */
    private function reservationsInScope(string $clubId, string $seasonId, ?string $calendarEntryId, ?PeriodConstraintSelection $selection): array
    {
        $repository = $this->entityManager->getRepository(Reservation::class);

        if (null === $calendarEntryId) {
            return $repository->findBy(
                ['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => null],
                ['id' => 'ASC'],
            );
        }

        // P2-14 : le plan et ses gymnases désactivés viennent de la SÉLECTION déjà
        // calculée — les re-résoudre ici était la 2e copie du même filtre. Pas de
        // sélection (période sans plan, ou non génératrice) : rien ne partira au solveur.
        if (!$selection instanceof PeriodConstraintSelection) {
            return [];
        }

        return array_values(array_filter(
            $repository->findBy(['schedulePlanId' => $selection->schedulePlanId], ['id' => 'ASC']),
            static fn (Reservation $reservation): bool => !isset($selection->disabledVenueIds[$reservation->getVenueId()]),
        ));
    }

    /**
     * @param list<string> $venueIds
     *
     * @return array<string, string>
     */
    private function venueNames(array $venueIds): array
    {
        if ([] === $venueIds) {
            return [];
        }

        $names = [];
        foreach ($this->venueRepository->findBy(['id' => $venueIds]) as $venue) {
            $names[$venue->getId()] = $venue->getName();
        }

        return $names;
    }
}
