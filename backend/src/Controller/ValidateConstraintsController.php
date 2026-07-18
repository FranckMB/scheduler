<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\TeamPeriodOverride;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintScope;
use App\Repository\CalendarEntryRepository;
use App\Repository\ConstraintRepository;
use App\Service\ConstraintValidationService;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly \App\Repository\TeamRepository $teamRepository,
        private readonly \Doctrine\ORM\EntityManagerInterface $entityManager,
        private readonly \App\Service\SchedulePlanProvisioner $schedulePlanProvisioner,
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

        $calendarEntryId = $this->requestedCalendarEntryId($request);
        if (null !== $calendarEntryId) {
            $calendarEntry = $this->calendarEntryRepository->find($calendarEntryId);
            if (!$calendarEntry instanceof CalendarEntry) {
                return $this->json(['error' => 'No active period.'], Response::HTTP_BAD_REQUEST);
            }

            $constraints = $this->constraintsForPeriod($clubId, $seasonId, $calendarEntry);
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

        $valid = [] === $errors && [] === $conflicts;

        return $this->json(
            ['valid' => $valid, 'errors' => $errors, 'conflicts' => $conflicts],
            $valid ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    private function requestedCalendarEntryId(?\Symfony\Component\HttpFoundation\Request $request): ?string
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
     * @return list<Constraint>
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
            return $dated;
        }

        // Les réglages de la période pendent au PLAN (inv. 5, lot C2) : on part du
        // déclencheur, on résout son plan. Une période génératrice en a toujours un
        // (il naît du geste, lot C1) ; un null ne peut venir que d'une donnée antérieure
        // au lot — sans réglage à appliquer, le récap reste juste.
        $schedulePlanId = $this->schedulePlanProvisioner->periodPlanId($calendarEntry->getId());
        if (null === $schedulePlanId) {
            return $dated;
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

        return [...$permanent, ...$dated];
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
