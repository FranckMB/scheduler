<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Doctrine\Filter\TenantFilter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class TenantFilterListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 8],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $clubId = $this->resolveClubId($event);
        $seasonId = $this->resolveSeasonId($event);

        if (null !== $seasonId) {
            $event->getRequest()->attributes->set('_season_id', $seasonId);
        }

        if (null === $clubId) {
            return;
        }

        $filterCollection = $this->entityManager->getFilters();

        /** @var TenantFilter $filter */
        $filter = $filterCollection->enable('tenant_filter');
        $filter->setParameter('club_id', $clubId, 'uuid');

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            'SET LOCAL app.club_id = '.$connection->quote($clubId)
        );
    }

    private function resolveClubId(RequestEvent $event): ?string
    {
        $request = $event->getRequest();

        $clubId = $request->attributes->get('_club_id');
        if (\is_string($clubId) && '' !== $clubId) {
            return $clubId;
        }

        $clubId = $request->headers->get('X-Club-Id');
        if (\is_string($clubId) && '' !== $clubId) {
            return $clubId;
        }

        return null;
    }

    private function resolveSeasonId(RequestEvent $event): ?string
    {
        $request = $event->getRequest();

        $seasonId = $request->attributes->get('_season_id');
        if (\is_string($seasonId) && '' !== $seasonId) {
            return $seasonId;
        }

        $seasonId = $request->headers->get('X-Season-Id');
        if (\is_string($seasonId) && '' !== $seasonId) {
            return $seasonId;
        }

        return null;
    }
}
