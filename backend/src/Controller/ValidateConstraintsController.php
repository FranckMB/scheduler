<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\Reservation;
use App\Entity\TeamPeriodOverride;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Entity\VenuePeriodOverride;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintScope;
use App\Enum\VenuePeriodMode;
use App\Repository\CalendarEntryRepository;
use App\Repository\ConstraintRepository;
use App\Repository\TeamRepository;
use App\Repository\VenueRepository;
use App\Service\CoachDoubleBookingDetector;
use App\Service\ConstraintValidationService;
use App\Service\ManagementAccessGuard;
use App\Service\ScheduleConstraintBuilder;
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
        $calendarEntryId = $this->requestedCalendarEntryId($request);
        if (null !== $calendarEntryId) {
            $calendarEntry = $this->calendarEntryRepository->find($calendarEntryId);
            if (!$calendarEntry instanceof CalendarEntry) {
                return $this->json(['error' => 'No active period.'], Response::HTTP_BAD_REQUEST);
            }

            ['constraints' => $constraints, 'warnings' => $warnings] = $this->constraintsForPeriod($clubId, $seasonId, $calendarEntry);
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
            $this->coachDoubleBookingDetector->detect($this->reservationsInScope($clubId, $seasonId, $calendarEntryId), $clubId, $seasonId),
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
     * @return array{constraints: list<Constraint>, warnings: list<string>}
     */
    private function constraintsForPeriod(string $clubId, string $seasonId, CalendarEntry $calendarEntry): array
    {
        /** @var list<Constraint> $dated */
        // P2-5 E1 : les datées d'une SEMAINE enfant vivent sur sa mère (source unique
        // datedConstraintSourceId) — le gate pré-solve doit valider le MÊME jeu que
        // celui que buildForOverlay enverra au solveur (revue #262 round 2).
        $dated = $this->constraintRepository->findBy(['calendarEntryId' => $calendarEntry->datedConstraintSourceId(), 'clubId' => $clubId]);
        $periodType = $calendarEntry->getPeriodType();
        if (!\in_array($periodType, [CalendarEntryPeriodType::CLOSURE, CalendarEntryPeriodType::HOLIDAY], true)) {
            return ['constraints' => $dated, 'warnings' => []];
        }

        // Les réglages de la période pendent au PLAN (inv. 5, lot C2) : on part du
        // déclencheur, on résout son plan. Une période génératrice en a toujours un
        // (il naît du geste, lot C1) ; un null ne peut venir que d'une donnée antérieure
        // au lot — sans réglage à appliquer, le récap reste juste.
        $schedulePlanId = $this->schedulePlanProvisioner->periodPlanId($calendarEntry->getId());
        if (null === $schedulePlanId) {
            return ['constraints' => $dated, 'warnings' => []];
        }

        $periodOverrides = [];
        foreach ($this->entityManager->getRepository(ConstraintPeriodOverride::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $override) {
            $periodOverrides[$override->getConstraintId()] = $override->isActive();
        }

        $deactivatedTeamIds = [];
        foreach ($this->entityManager->getRepository(TeamPeriodOverride::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $override) {
            if (!$override->isActive()) {
                $deactivatedTeamIds[$override->getTeamId()] = true;
            }
        }

        $activeTeamIds = [];
        foreach ($this->teamRepository->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $team) {
            if (!isset($deactivatedTeamIds[$team->getId()])) {
                $activeTeamIds[$team->getId()] = true;
            }
        }

        $activeTagTeamIds = $this->activeTagTeamIdsByName($clubId, $seasonId, $activeTeamIds);
        $permanent = [];
        foreach ($this->constraintRepository->findPermanentByClubSeason($clubId, $seasonId) as $constraint) {
            $keepByDefault = CalendarEntryPeriodType::CLOSURE === $periodType || ConstraintScope::FACILITY !== $constraint->getScope();
            $keep = \array_key_exists($constraint->getId(), $periodOverrides) ? $periodOverrides[$constraint->getId()] : $keepByDefault;
            if (!$keep) {
                continue;
            }
            if (ConstraintScope::TEAM === $constraint->getScope() && isset($deactivatedTeamIds[$constraint->getScopeTargetId() ?? ''])) {
                continue;
            }
            $targetTag = $constraint->getConfig()['targetTag'] ?? null;
            if (ConstraintScope::CLUB === $constraint->getScope() && \is_string($targetTag) && '' !== $targetTag && [] === ($activeTagTeamIds[$targetTag] ?? [])) {
                continue;
            }
            $permanent[] = $constraint;
        }

        // #8 — miroir EXACT du filtre gymnases de ScheduleConstraintBuilder::buildForOverlay :
        // une contrainte qui nomme un gymnase désactivé ne partira pas au solveur. Elle sort
        // donc aussi du gate (sinon le récap valide un jeu que le payload n'aura pas), et le
        // dirigeant en est AVERTI plutôt que de la voir disparaître en silence. Le filtre
        // s'applique aux PERMANENTES **et** aux DATÉES — le builder filtre les deux.
        $disabledVenueIds = $this->disabledVenueIds($schedulePlanId);
        $venueNames = $this->venueNames($disabledVenueIds);

        $constraints = [];
        $warnings = [];
        foreach ([...$permanent, ...$dated] as $constraint) {
            $disabledVenueId = $this->disabledVenueNamedBy($constraint, $disabledVenueIds);
            if (null !== $disabledVenueId) {
                $warnings[] = \sprintf(
                    '« %s » vise le gymnase %s, désactivé pour cette période : elle ne sera pas appliquée.',
                    $constraint->getName(),
                    $venueNames[$disabledVenueId] ?? $disabledVenueId,
                );

                continue;
            }
            $constraints[] = $constraint;
        }

        return ['constraints' => $constraints, 'warnings' => $warnings];
    }

    /**
     * Gymnases DÉSACTIVÉS pour ce plan de période (sparse : pas de ligne = le gymnase sert).
     *
     * @return array<string, true>
     */
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
    private function reservationsInScope(string $clubId, string $seasonId, ?string $calendarEntryId): array
    {
        $repository = $this->entityManager->getRepository(Reservation::class);

        if (null === $calendarEntryId) {
            return $repository->findBy(
                ['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => null],
                ['id' => 'ASC'],
            );
        }

        $schedulePlanId = $this->schedulePlanProvisioner->periodPlanId($calendarEntryId);
        if (null === $schedulePlanId) {
            return []; // période sans plan : rien ne partira au solveur
        }

        $disabledVenueIds = $this->disabledVenueIds($schedulePlanId);

        return array_values(array_filter(
            $repository->findBy(['schedulePlanId' => $schedulePlanId], ['id' => 'ASC']),
            static fn (Reservation $reservation): bool => !isset($disabledVenueIds[$reservation->getVenueId()]),
        ));
    }

    /**
     * @return array<string, true>
     */
    private function disabledVenueIds(string $schedulePlanId): array
    {
        $disabledVenueIds = [];
        foreach ($this->entityManager->getRepository(VenuePeriodOverride::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $override) {
            if (VenuePeriodMode::DISABLED === $override->getMode()) {
                $disabledVenueIds[$override->getVenueId()] = true;
            }
        }

        return $disabledVenueIds;
    }

    /**
     * @param array<string, true> $disabledVenueIds
     *
     * @return array<string, string>
     */
    private function venueNames(array $disabledVenueIds): array
    {
        if ([] === $disabledVenueIds) {
            return [];
        }

        $names = [];
        foreach ($this->venueRepository->findBy(['id' => array_keys($disabledVenueIds)]) as $venue) {
            $names[$venue->getId()] = $venue->getName();
        }

        return $names;
    }

    /**
     * L'id du gymnase désactivé que cette contrainte NOMME, ou null. Les deux façons de
     * nommer un gymnase sont celles du builder : le scope FACILITY, et les clés de config
     * de `ScheduleConstraintBuilder::VENUE_CONFIG_KEYS` (source unique — dupliquer la liste
     * est exactement ce qui a produit la dérive gate/payload).
     *
     * @param array<string, true> $disabledVenueIds
     */
    private function disabledVenueNamedBy(Constraint $constraint, array $disabledVenueIds): ?string
    {
        $scopeTargetId = $constraint->getScopeTargetId();
        if (ConstraintScope::FACILITY === $constraint->getScope() && \is_string($scopeTargetId) && isset($disabledVenueIds[$scopeTargetId])) {
            return $scopeTargetId;
        }

        $config = $constraint->getConfig();
        foreach (ScheduleConstraintBuilder::VENUE_CONFIG_KEYS as $venueKey) {
            $venueId = $config[$venueKey] ?? null;
            if (\is_string($venueId) && isset($disabledVenueIds[$venueId])) {
                return $venueId;
            }
        }

        return null;
    }

    /**
     * @param array<string, true> $activeTeamIds
     *
     * @return array<string, array<string, true>>
     */
    private function activeTagTeamIdsByName(string $clubId, string $seasonId, array $activeTeamIds): array
    {
        $tagNameById = [];
        foreach ($this->entityManager->getRepository(TeamTag::class)->findBy(['clubId' => $clubId]) as $tag) {
            $tagNameById[$tag->getId()] = $tag->getName();
        }

        $tagTeamIdsByName = [];
        foreach ($this->entityManager->getRepository(TeamTagAssignment::class)->findBy(['seasonId' => $seasonId]) as $assignment) {
            if (!isset($activeTeamIds[$assignment->getTeamId()])) {
                continue;
            }
            $tagName = $tagNameById[$assignment->getTagId()] ?? null;
            if (!\is_string($tagName) || '' === $tagName) {
                continue;
            }
            $tagTeamIdsByName[$tagName][$assignment->getTeamId()] = true;
        }

        return $tagTeamIdsByName;
    }
}
