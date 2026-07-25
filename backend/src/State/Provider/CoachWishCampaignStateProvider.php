<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use App\ApiResource\CoachWishCampaignResource;
use App\Entity\CoachWishCampaign;
use App\Service\CoachWishCampaignPresenter;
use App\Service\ManagementAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @extends AbstractStateProvider<CoachWishCampaign, CoachWishCampaignResource>
 */
class CoachWishCampaignStateProvider extends AbstractStateProvider
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        Pagination $pagination,
        private readonly CoachWishCampaignPresenter $presenter,
        private readonly ManagementAccessGuard $managementAccessGuard,
    ) {
        parent::__construct($entityManager, $requestStack, $pagination);
    }

    /**
     * La ressource expose `coaches[].token` — le SECRET du lien public de chaque coach. La
     * LECTURE est donc management-only (SEC-07), au même titre que l'écriture : sans cette
     * garde, tout membre authentifié lirait les tokens et pourrait usurper les réponses.
     *
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $this->managementAccessGuard->assertManager();

        return parent::provide($operation, $uriVariables, $context);
    }

    protected function getEntityClass(): string
    {
        return CoachWishCampaign::class;
    }

    /**
     * @param CoachWishCampaign $entity
     */
    protected function mapEntityToOutput(object $entity): CoachWishCampaignResource
    {
        return $this->presenter->toResource($entity);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, CoachWishCampaignResource>
     */
    protected function provideCollection(Operation $operation, array $context, ?string $clubId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(CoachWishCampaign::class, 'e');

        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            $calendarEntryId = $request->query->get('calendarEntryId');
            if (null !== $calendarEntryId && '' !== $calendarEntryId) {
                $qb->andWhere('e.calendarEntryId = :calendarEntryId')->setParameter('calendarEntryId', $calendarEntryId);
            }
        }

        $qb->orderBy('e.id', 'ASC');

        return array_map([$this, 'mapEntityToOutput'], $qb->getQuery()->getResult());
    }
}
