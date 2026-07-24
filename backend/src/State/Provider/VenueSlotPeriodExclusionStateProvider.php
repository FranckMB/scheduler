<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\VenueSlotPeriodExclusionResource;
use App\Entity\VenueSlotPeriodExclusion;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<VenueSlotPeriodExclusion, VenueSlotPeriodExclusionResource>
 */
class VenueSlotPeriodExclusionStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return VenueSlotPeriodExclusion::class;
    }

    /**
     * @param VenueSlotPeriodExclusion $entity
     */
    protected function mapEntityToOutput(object $entity): VenueSlotPeriodExclusionResource
    {
        return VenueSlotPeriodExclusionResource::fromEntity($entity);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, VenueSlotPeriodExclusionResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(VenueSlotPeriodExclusion::class, 'e');

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            // Les exclusions sont toujours consultées par période ; un créneau affine encore.
            $schedulePlanId = $request->query->get('schedulePlanId');
            if (null !== $schedulePlanId && '' !== $schedulePlanId) {
                $qb->andWhere('e.schedulePlanId = :schedulePlanId')->setParameter('schedulePlanId', $schedulePlanId);
            }

            $venueTrainingSlotId = $request->query->get('venueTrainingSlotId');
            if (null !== $venueTrainingSlotId && '' !== $venueTrainingSlotId) {
                $qb->andWhere('e.venueTrainingSlotId = :venueTrainingSlotId')->setParameter('venueTrainingSlotId', $venueTrainingSlotId);
            }
        }

        $qb->orderBy('e.id', 'ASC');

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }
}
