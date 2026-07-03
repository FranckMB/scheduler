<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\State\Pagination\Pagination;
use App\ApiResource\ClubResource;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @extends AbstractStateProvider<Club, ClubResource>
 */
class ClubStateProvider extends AbstractStateProvider
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        Pagination $pagination,
        private readonly Security $security,
    ) {
        parent::__construct($entityManager, $requestStack, $pagination);
    }

    protected function getEntityClass(): string
    {
        return Club::class;
    }

    /**
     * SEC-01: Club has no club_id column, so the tenant filter does not scope it.
     * Bound the collection to the clubs the caller is an active member of.
     */
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            // Fail-closed: no authenticated user → no clubs.
            $qb->andWhere('1 = 0');

            return false;
        }

        $qb->andWhere($qb->expr()->in(
            'e.id',
            'SELECT cu.clubId FROM ' . ClubUser::class . ' cu WHERE cu.userId = :cs_uid AND cu.isActive = true',
        ))->setParameter('cs_uid', $user->getId());

        return false;
    }

    /**
     * SEC-01: only return the club when the caller has an active membership in it.
     */
    protected function provideItem(array $uriVariables, ?string $clubId): ?object
    {
        $id = $uriVariables['id'] ?? null;
        if (!$id) {
            return null;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $membership = $this->entityManager->getRepository(ClubUser::class)->findOneBy([
            'userId' => $user->getId(),
            'clubId' => $id,
            'isActive' => true,
        ]);
        if (null === $membership) {
            return null;
        }

        $entity = $this->entityManager->find(Club::class, $id);
        if (!$entity instanceof Club) {
            return null;
        }

        return $this->mapEntityToOutput($entity);
    }

    /**
     * @param Club $entity
     */
    protected function mapEntityToOutput(object $entity): ClubResource
    {
        return ClubResource::fromEntity($entity);
    }
}
