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
            ->addOrderBy('e.startTime', 'ASC')
            // UUID PK tiebreaker → stable pagination (see AbstractStateProvider).
            ->addOrderBy('e.id', 'ASC');

        if ($this->pagination->isEnabled($operation, $context)) {
            $qb->setFirstResult($this->pagination->getOffset($operation, $context))
               ->setMaxResults($this->pagination->getLimit($operation, $context));
        }

        // Base/overlay layering (same as constraints): ?schedulePlanId=<id> lists a
        // period overlay's reservations; without it, the base plan (schedulePlanId
        // IS NULL) so the wizard's Réserver tab shows the permanent reservations.
        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request && $request->query->has('schedulePlanId')) {
            $qb->andWhere('e.schedulePlanId = :schedulePlanId')
               ->setParameter('schedulePlanId', $request->query->get('schedulePlanId'));
        } else {
            $qb->andWhere('e.schedulePlanId IS NULL');
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
