<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\ConstraintPeriodOverrideResource;
use App\Entity\ConstraintPeriodOverride;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<ConstraintPeriodOverride, ConstraintPeriodOverrideResource>
 */
class ConstraintPeriodOverrideStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return ConstraintPeriodOverride::class;
    }

    /**
     * @param ConstraintPeriodOverride $entity
     */
    protected function mapEntityToOutput(object $entity): ConstraintPeriodOverrideResource
    {
        return ConstraintPeriodOverrideResource::fromEntity($entity);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, ConstraintPeriodOverrideResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(ConstraintPeriodOverride::class, 'e');

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            // Overrides are always consulted per period; a constraintId narrows further.
            $calendarEntryId = $request->query->get('calendarEntryId');
            if (null !== $calendarEntryId && '' !== $calendarEntryId) {
                $qb->andWhere('e.calendarEntryId = :calendarEntryId')->setParameter('calendarEntryId', $calendarEntryId);
            }

            $constraintId = $request->query->get('constraintId');
            if (null !== $constraintId && '' !== $constraintId) {
                $qb->andWhere('e.constraintId = :constraintId')->setParameter('constraintId', $constraintId);
            }
        }

        $qb->orderBy('e.id', 'ASC');

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }
}
