<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Fixture;
use App\Entity\VenueUnavailability;
use App\Service\SeasonResolver;
use App\Service\TrainingCalendarContext;
use App\Service\VenueUnavailabilityImpact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/venue-unavailability-impact — the cockpit alert feed (P1-4 PR B):
 * for each unavailability of the season, the placed matches and the training
 * sessions it affects. Read-only, recomputed at each call, nothing persisted —
 * the SAME picture as the match radar (shared TrainingCalendarContext).
 *
 * Dashed path: no collision with API Platform's /api/venue_unavailabilities
 * CRUD (FixtureConflictsController idiom).
 */
final class VenueUnavailabilityImpactController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly TrainingCalendarContext $trainingCalendarContext,
        private readonly VenueUnavailabilityImpact $impact,
    ) {}

    #[Route('/api/venue-unavailability-impact', name: 'api_venue_unavailability_impact', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var list<VenueUnavailability> $unavailabilities */
        $unavailabilities = $this->entityManager->getRepository(VenueUnavailability::class)->findBy([]);
        /** @var list<Fixture> $fixtures */
        $fixtures = $this->entityManager->getRepository(Fixture::class)->findBy([]);

        $season = $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId);
        $context = $this->trainingCalendarContext->load($season?->getId());

        return $this->json([
            'clubId' => $clubId,
            'seasonId' => $season?->getId(),
            'items' => $this->impact->build(
                $unavailabilities,
                $fixtures,
                $context['seasonScheduleId'],
                $context['activePeriods'],
                $context['slotsBySchedule'],
            ),
        ]);
    }
}
