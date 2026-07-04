<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\State\Pagination\Pagination;
use App\ApiResource\CalendarEntryResource;
use App\Entity\CalendarEntry;
use App\Repository\SeasonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @extends AbstractStateProvider<CalendarEntry, CalendarEntryResource>
 */
class CalendarEntryStateProvider extends AbstractStateProvider
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        Pagination $pagination,
        private readonly SeasonRepository $seasonRepository,
    ) {
        parent::__construct($entityManager, $requestStack, $pagination);
    }

    protected function getEntityClass(): string
    {
        return CalendarEntry::class;
    }

    /**
     * Filters only — the base class keeps hydra pagination metadata (BCK-05).
     * Returns false: these are partial filters, not a single-parent bound, so
     * the result stays paginated.
     */
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return false;
        }

        // Scope to the club's active season so a rolled-over club never sees a
        // previous season's entries (the tenant filter only scopes club_id).
        $clubId = $request->attributes->get('_club_id') ?? $request->headers->get('X-Club-Id');
        if (\is_string($clubId) && '' !== $clubId) {
            $season = $this->seasonRepository->findActiveByClubId($clubId);
            if (null !== $season) {
                $qb->andWhere('e.seasonId = :seasonId')
                   ->setParameter('seasonId', $season->getId());
            }
        }

        if ($request->query->has('kind')) {
            $qb->andWhere('e.kind = :kind')
               ->setParameter('kind', $request->query->get('kind'));
        }
        if ($request->query->has('status')) {
            $qb->andWhere('e.status = :status')
               ->setParameter('status', $request->query->get('status'));
        }
        // Window-overlap filter for the visible month: any entry whose
        // [startDate, endDate] range intersects [from, to].
        $from = $request->query->get('from');
        if (\is_string($from) && '' !== $from) {
            $qb->andWhere('e.endDate >= :from')
               ->setParameter('from', $this->assertDate($from, 'from'));
        }
        $to = $request->query->get('to');
        if (\is_string($to) && '' !== $to) {
            $qb->andWhere('e.startDate <= :to')
               ->setParameter('to', $this->assertDate($to, 'to'));
        }

        $qb->orderBy('e.startDate', 'ASC');

        return false;
    }

    /**
     * @param CalendarEntry $entity
     */
    protected function mapEntityToOutput(object $entity): CalendarEntryResource
    {
        return CalendarEntryResource::fromEntity($entity);
    }

    private function assertDate(string $value, string $param): string
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new BadRequestHttpException(\sprintf('Query param "%s" must be a date (YYYY-MM-DD).', $param));
        }

        return $value;
    }
}
