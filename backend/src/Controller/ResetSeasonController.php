<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\ConstraintConflict;
use App\Entity\PeriodReminderLog;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
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

        // Disable the Doctrine tenant + season filters for the bulk DELETEs:
        // they append `{table}.club_id/season_id = …` using the table name as
        // the alias, which is invalid SQL for the reserved-word `constraint`
        // table. The deletes are already scoped by clubId + seasonId
        // explicitly, and PostgreSQL RLS (app.club_id GUC) still enforces the
        // tenant boundary at the DB level.
        $filters = $this->entityManager->getFilters();
        foreach (['tenant_filter', 'season_filter'] as $filterName) {
            if ($filters->isEnabled($filterName)) {
                $filters->disable($filterName);
            }
        }

        $deleted = 0;

        // Children WITHOUT club/season columns first, resolved through their parent:
        // conflicts hang off schedules, reminder logs off calendar entries. They must
        // go before their parents' bulk DELETE or they orphan silently.
        $deleted += $this->deleteBySubQuery(ConstraintConflict::class, 'scheduleId', Schedule::class, $clubId, $seasonId);
        $deleted += $this->deleteBySubQuery(PeriodReminderLog::class, 'calendarEntryId', CalendarEntry::class, $clubId, $seasonId);

        foreach ([
            ScheduleDiagnostic::class,
            ScheduleSlotTemplate::class,
            Constraint::class,
            TeamCoach::class,
            CoachPlayerMembership::class,
            CalendarEntry::class,
            Schedule::class,
            Team::class,
            Coach::class,
            VenueTrainingSlot::class,
            Venue::class,
        ] as $entityClass) {
            $deleted += $this->deleteByClubSeason($entityClass, $clubId, $seasonId);
        }

        // The season anchors are gone with the schedules: clear them, otherwise the
        // baseline points at a deleted row and the cockpit stays "unlocked"
        // (socleValidatedAt sticky) with no plan behind it.
        $season = $this->entityManager->getRepository(Season::class)->find($seasonId);
        if ($season instanceof Season && $season->getClubId() === $clubId) {
            $season->setBaselineScheduleId(null);
            $season->setSocleValidatedAt(null);
            $this->entityManager->flush();
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

    /**
     * Delete rows of $entityClass whose $parentRefField points at a parent row
     * (of $parentClass) belonging to this club+season. DQL DELETE with subquery.
     */
    private function deleteBySubQuery(string $entityClass, string $parentRefField, string $parentClass, string $clubId, string $seasonId): int
    {
        $sub = $this->entityManager->createQueryBuilder()
            ->select('p.id')
            ->from($parentClass, 'p')
            ->where('p.clubId = :clubId')
            ->andWhere('p.seasonId = :seasonId')
            ->getDQL();

        return (int) $this->entityManager->createQueryBuilder()
            ->delete($entityClass, 'e')
            ->where(\sprintf('e.%s IN (%s)', $parentRefField, $sub))
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->execute();
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
