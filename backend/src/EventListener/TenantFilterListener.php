<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Doctrine\Filter\TenantFilter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Activates the tenant SQL filter and sets the PostgreSQL session variable
 * `app.club_id` on every main HTTP request.
 *
 * Phase 1 stub: club_id is resolved from request attributes or a test header.
 * In production this will be extracted from the JWT token / security token.
 */
class TenantFilterListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Run before the security firewall (priority 8) so the filter is
            // ready when controllers / voters execute.
            KernelEvents::REQUEST => ['onKernelRequest', 8],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $clubId = $this->resolveClubId($event);

        // Safety: never enable the filter (and never SET LOCAL) when we
        // do not have a resolved tenant. This prevents accidental data
        // leakage in CLI or during unauthenticated requests.
        if ($clubId === null) {
            return;
        }

        $filterCollection = $this->entityManager->getFilters();

        /** @var TenantFilter $filter */
        $filter = $filterCollection->enable('tenant_filter');
        $filter->setParameter('club_id', $clubId, 'uuid');

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            'SET LOCAL app.club_id = ' . $connection->quote($clubId)
        );
    }

    private function resolveClubId(RequestEvent $event): ?string
    {
        $request = $event->getRequest();

        // Phase 1 stub — will be replaced by JWT extraction once
        // authentication is wired (Phase 2).
        $clubId = $request->attributes->get('_club_id');
        if (\is_string($clubId) && $clubId !== '') {
            return $clubId;
        }

        // Allow manual override via header for integration testing.
        $clubId = $request->headers->get('X-Club-Id');
        if (\is_string($clubId) && $clubId !== '') {
            return $clubId;
        }

        return null;
    }
}
