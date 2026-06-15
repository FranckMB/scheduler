<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\CoachUnavailability;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamConstraint;
use App\Entity\Venue;
use App\Entity\VenueAvailability;
use App\Entity\VenueClosure;
use App\Entity\VenueConstraint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/reset-season', name: 'reset_season', methods: ['DELETE'])]
final class ResetSeasonController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $this->resolveIdentifier($request?->attributes->get('_club_id'), $request?->headers->get('X-Club-Id'));
        $seasonId = $this->resolveIdentifier($request?->attributes->get('_season_id'), $request?->headers->get('X-Season-Id'));

        if (null === $clubId || null === $seasonId) {
            return $this->json(['error' => 'Missing club or season context.'], Response::HTTP_BAD_REQUEST);
        }

        $deleted = 0;
        foreach ([
            ScheduleDiagnostic::class,
            ScheduleSlotTemplate::class,
            VenueConstraint::class,
            VenueClosure::class,
            VenueAvailability::class,
            TeamConstraint::class,
            TeamCoach::class,
            CoachUnavailability::class,
            CoachPlayerMembership::class,
            Schedule::class,
            Team::class,
            Coach::class,
            Venue::class,
        ] as $entityClass) {
            $deleted += $this->deleteByClubSeason($entityClass, $clubId, $seasonId);
        }

        $this->entityManager->clear();

        return $this->json([
            'status' => 'ok',
            'clubId' => $clubId,
            'seasonId' => $seasonId,
            'deleted' => $deleted,
        ]);
    }

    private function resolveIdentifier(mixed $attribute, mixed $header): ?string
    {
        foreach ([$attribute, $header] as $value) {
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }

    private function deleteByClubSeason(string $entityClass, string $clubId, string $seasonId): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->delete($entityClass, 'e')
            ->where('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->execute();
    }
}
