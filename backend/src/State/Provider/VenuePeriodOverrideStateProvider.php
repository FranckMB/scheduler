<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\VenuePeriodOverrideResource;
use App\Entity\VenuePeriodOverride;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<VenuePeriodOverride, VenuePeriodOverrideResource>
 */
class VenuePeriodOverrideStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return VenuePeriodOverride::class;
    }

    /**
     * @param VenuePeriodOverride $entity
     */
    protected function mapEntityToOutput(object $entity): VenuePeriodOverrideResource
    {
        return VenuePeriodOverrideResource::fromEntity($entity);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, VenuePeriodOverrideResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(VenuePeriodOverride::class, 'e');

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            // Les réglages sont toujours consultés par période ; un venueId affine encore.
            $schedulePlanId = $request->query->get('schedulePlanId');
            if (null !== $schedulePlanId && '' !== $schedulePlanId) {
                $qb->andWhere('e.schedulePlanId = :schedulePlanId')->setParameter('schedulePlanId', $schedulePlanId);
            }

            $venueId = $request->query->get('venueId');
            if (null !== $venueId && '' !== $venueId) {
                $qb->andWhere('e.venueId = :venueId')->setParameter('venueId', $venueId);
            }
        }

        $qb->orderBy('e.id', 'ASC');

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }
}
