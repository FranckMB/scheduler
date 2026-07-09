<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\ReservationResource;
use App\Entity\Reservation;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<Reservation, ReservationResource>
 */
class ReservationStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Reservation::class;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, ReservationResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityClass(), 'e')
            ->orderBy('e.dayOfWeek', 'ASC')
            ->addOrderBy('e.startTime', 'ASC');

        if ($this->pagination->isEnabled($operation, $context)) {
            $qb->setFirstResult($this->pagination->getOffset($operation, $context))
               ->setMaxResults($this->pagination->getLimit($operation, $context));
        }

        // Base/overlay layering (same as constraints): ?calendarEntryId=<id> lists a
        // period overlay's reservations; without it, the base plan (calendarEntryId
        // IS NULL) so the wizard's Réserver tab shows the permanent reservations.
        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request && $request->query->has('calendarEntryId')) {
            $qb->andWhere('e.calendarEntryId = :calendarEntryId')
               ->setParameter('calendarEntryId', $request->query->get('calendarEntryId'));
        } else {
            $qb->andWhere('e.calendarEntryId IS NULL');
        }

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }

    /**
     * @param Reservation $entity
     */
    protected function mapEntityToOutput(object $entity): ReservationResource
    {
        return ReservationResource::fromEntity($entity);
    }
}
