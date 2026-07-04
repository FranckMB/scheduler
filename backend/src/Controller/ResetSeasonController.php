<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Entity\Venue;
use App\Repository\ClubUserRepository;
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
        private readonly ClubUserRepository $clubUserRepository,
    ) {}

    public function __invoke(): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $this->resolveIdentifier($request?->attributes->get('_club_id'), $request?->headers->get('X-Club-Id'));
        $seasonId = $this->resolveIdentifier($request?->attributes->get('_season_id'), $request?->headers->get('X-Season-Id'));

        if (null === $clubId || null === $seasonId) {
            return $this->json(['error' => 'Missing club or season context.'], Response::HTTP_BAD_REQUEST);
        }

        // Wiping the whole club is a management action: gate on an active
        // management membership (mirrors ImportController/ClubStateProcessor).
        // The tenant listener already resolved _club_id from the caller's JWT
        // membership, so a non-member cannot reach another club here.
        $user = $this->getUser();
        $membership = $user instanceof User
            ? $this->clubUserRepository->findActiveMembership($user->getId(), $clubId)
            : null;
        if (null === $membership || !$this->clubUserRepository->isManagementRole($membership->getRole())) {
            return $this->json(['error' => 'Management role required.'], Response::HTTP_FORBIDDEN);
        }

        // Disable the Doctrine tenant filter for the bulk DELETEs: it appends
        // `{table}.club_id = …` using the table name as the alias, which is
        // invalid SQL for the reserved-word `constraint` table. The deletes are
        // already scoped by clubId + seasonId explicitly, and PostgreSQL RLS
        // (app.club_id GUC) still enforces the tenant boundary at the DB level.
        $filters = $this->entityManager->getFilters();
        if ($filters->isEnabled('tenant_filter')) {
            $filters->disable('tenant_filter');
        }

        $deleted = 0;
        foreach ([
            ScheduleDiagnostic::class,
            ScheduleSlotTemplate::class,
            Constraint::class,
            TeamCoach::class,
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
