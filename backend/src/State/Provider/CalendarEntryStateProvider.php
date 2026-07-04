<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\CalendarEntryResource;
use App\Entity\CalendarEntry;

/**
 * @extends AbstractStateProvider<CalendarEntry, CalendarEntryResource>
 */
class CalendarEntryStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return CalendarEntry::class;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, CalendarEntryResource>
     */
    protected function provideCollection(\ApiPlatform\Metadata\Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityClass(), 'e');

        if ($this->pagination->isEnabled($operation, $context)) {
            $offset = $this->pagination->getOffset($operation, $context);
            $limit = $this->pagination->getLimit($operation, $context);
            $qb->setFirstResult($offset)
               ->setMaxResults($limit);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof \Symfony\Component\HttpFoundation\Request) {
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
                   ->setParameter('from', $from);
            }
            $to = $request->query->get('to');
            if (\is_string($to) && '' !== $to) {
                $qb->andWhere('e.startDate <= :to')
                   ->setParameter('to', $to);
            }
        }

        $qb->orderBy('e.startDate', 'ASC');

        $query = $qb->getQuery();
        $results = $query->getResult();

        return array_map([$this, 'mapEntityToOutput'], $results);
    }

    /**
     * @param CalendarEntry $entity
     */
    protected function mapEntityToOutput(object $entity): CalendarEntryResource
    {
        return CalendarEntryResource::fromEntity($entity);
    }
}
