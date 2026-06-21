<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\VenueTrainingSlotResource;
use App\Entity\VenueTrainingSlot;

/**
 * @extends AbstractStateProvider<VenueTrainingSlot, VenueTrainingSlotResource>
 */
class VenueTrainingSlotStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return VenueTrainingSlot::class;
    }

    /**
     * @param VenueTrainingSlot $entity
     */
    protected function mapEntityToOutput(object $entity): VenueTrainingSlotResource
    {
        return VenueTrainingSlotResource::fromEntity($entity);
    }

    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(VenueTrainingSlot::class, 'e');

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof \Symfony\Component\HttpFoundation\Request) {
            $venueId = $request->query->get('venueId');
            if (null !== $venueId && '' !== $venueId) {
                $qb->andWhere('e.venueId = :venueId')->setParameter('venueId', $venueId);
            }

            $seasonId = $request->query->get('seasonId');
            if (null !== $seasonId && '' !== $seasonId) {
                $qb->andWhere('e.seasonId = :seasonId')->setParameter('seasonId', $seasonId);
            }
        }

        $qb->orderBy('e.dayOfWeek', 'ASC');

        if ($this->pagination->isEnabled($operation, $context)) {
            $offset = $this->pagination->getOffset($operation, $context);
            $limit = $this->pagination->getLimit($operation, $context);
            $qb->setFirstResult($offset)->setMaxResults($limit);
        }

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }
}
