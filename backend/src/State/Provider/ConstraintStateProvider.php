<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\ConstraintResource;
use App\Entity\Constraint;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<Constraint, ConstraintResource>
 */
class ConstraintStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    protected function getEntityClass(): string
    {
        return Constraint::class;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, ConstraintResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($this->getEntityClass(), 'e')
            // UUID PK order → stable offset pagination (see AbstractStateProvider): an
            // unordered collection reshuffles rows between page requests and collectionAll
            // silently drops boundary rows.
            ->orderBy('e.id', 'ASC');

        if ($this->pagination->isEnabled($operation, $context)) {
            $offset = $this->pagination->getOffset($operation, $context);
            $limit = $this->pagination->getLimit($operation, $context);
            $qb->setFirstResult($offset)
               ->setMaxResults($limit);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            if ($request->query->has('scope')) {
                $qb->andWhere('e.scope = :scope')
                   ->setParameter('scope', $request->query->get('scope'));
            }
            if ($request->query->has('family')) {
                $qb->andWhere('e.family = :family')
                   ->setParameter('family', $request->query->get('family'));
            }
            if ($request->query->has('ruleType')) {
                $qb->andWhere('e.ruleType = :ruleType')
                   ->setParameter('ruleType', $request->query->get('ruleType'));
            }
            $calendarEntryId = $this->uuidQueryParam($request, 'calendarEntryId');
            if (null !== $calendarEntryId) {
                $qb->andWhere('e.calendarEntryId = :calendarEntryId')
                   ->setParameter('calendarEntryId', $calendarEntryId);
            }
            // permanent=1 → base-plan constraints only (dated/period ones excluded),
            // so the wizard's season Constraints step doesn't show period constraints.
            if ('1' === $request->query->get('permanent')) {
                $qb->andWhere('e.calendarEntryId IS NULL');
            }
        }

        $query = $qb->getQuery();
        $results = $query->getResult();

        return array_map([$this, 'mapEntityToOutput'], $results);
    }

    /**
     * @param Constraint $entity
     */
    protected function mapEntityToOutput(object $entity): ConstraintResource
    {
        return ConstraintResource::fromEntity($entity);
    }
}
