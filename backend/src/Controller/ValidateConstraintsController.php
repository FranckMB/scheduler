<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Constraint;
use App\Repository\ConstraintRepository;
use App\Repository\SeasonRepository;
use App\Service\ConstraintValidationService;
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
        private readonly SeasonRepository $seasonRepository,
        private readonly ConstraintValidationService $validationService,
        private readonly RequestStack $requestStack,
    ) {}

    #[Route('/api/constraints/validate', name: 'api_constraints_validate', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');
        if (!\is_string($clubId) || '' === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $seasonId = $request?->attributes->get('_season_id');
        if (!\is_string($seasonId) || '' === $seasonId) {
            $season = $this->seasonRepository->findOneBy(['clubId' => $clubId, 'status' => 'active']);
            $seasonId = $season?->getId();
        }
        if (null === $seasonId) {
            return $this->json(['error' => 'No active season.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var list<Constraint> $constraints */
        $constraints = $this->constraintRepository->findByClubSeason($clubId, $seasonId);

        $errors = [];
        foreach ($constraints as $constraint) {
            $messages = $this->validationService->validate($constraint);
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
}
