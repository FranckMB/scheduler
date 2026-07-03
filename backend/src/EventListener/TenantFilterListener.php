<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Doctrine\Filter\TenantFilter;
use App\Entity\User;
use App\Repository\ClubUserRepository;
use App\Repository\SeasonRepository;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class TenantFilterListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly SeasonRepository $seasonRepository,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly TenantConnectionContext $tenantConnectionContext,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priority 7: AFTER the security firewall (priority 8) so the JWT user is
        // authenticated — the club is derived from the user's membership when no
        // X-Club-Id header is sent. Running before auth left the tenant unresolved
        // (no RLS) and leaked other clubs' data on collection reads.
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 7],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Anti-staleness guard: never inherit a GUC from a previous request on
        // the same connection (shared kernel in tests, worker loops).
        $this->tenantConnectionContext->clear();

        $request = $event->getRequest();
        $user = $this->authenticatedUser();

        // Single active club per user: when no explicit tenant is supplied, derive
        // it from the JWT user's active membership. Header/attribute stay as an
        // override (e.g. tests). club_user is readable without a GUC by design
        // (RLS bootstrap exception).
        $clubId = $this->resolveClubId($request, $user);

        if (null === $clubId) {
            // No tenant: only an explicit season header/attribute can apply (the
            // active-season fallback needs a club anyway).
            $explicitSeason = $this->resolveExplicitSeasonId($request);
            if (null !== $explicitSeason) {
                $request->attributes->set('_season_id', $explicitSeason);
            }

            return;
        }

        $request->attributes->set('_club_id', $clubId);

        // Validate that the authenticated user belongs to the requested club
        // (blocks a spoofed X-Club-Id header pointing at another tenant).
        if ($user instanceof User) {
            $membership = $this->clubUserRepository->findOneBy([
                'userId' => $user->getId(),
                'clubId' => $clubId,
                'isActive' => true,
            ]);
            if (null === $membership) {
                $event->setResponse(new JsonResponse(
                    ['error' => 'You do not have access to this club'],
                    403,
                ));

                return;
            }
        }

        $filterCollection = $this->entityManager->getFilters();

        /** @var TenantFilter $filter */
        $filter = $filterCollection->enable('tenant_filter');
        $filter->setParameter('club_id', $clubId, 'uuid');

        // Session-scoped GUC read by the RLS policies (the old SET LOCAL was a
        // no-op outside a transaction — see TenantConnectionContext).
        $this->tenantConnectionContext->setClubId($clubId);

        // Season AFTER the GUC: the active-season fallback queries the
        // RLS-protected season table — before the GUC it always found 0 rows
        // and the header-less frontend flow lost its _season_id.
        $seasonId = $this->resolveSeasonId($request, $clubId);
        if (null !== $seasonId) {
            $request->attributes->set('_season_id', $seasonId);
        }
    }

    private function authenticatedUser(): ?User
    {
        $token = $this->tokenStorage->getToken();
        if (null === $token) {
            return null;
        }

        $user = $token->getUser();

        return $user instanceof User ? $user : null;
    }

    private function resolveClubId(Request $request, ?User $user): ?string
    {
        $clubId = $request->attributes->get('_club_id');
        if (\is_string($clubId) && '' !== $clubId) {
            return $clubId;
        }

        $clubId = $request->headers->get('X-Club-Id');
        if (\is_string($clubId) && '' !== $clubId) {
            return $clubId;
        }

        // Fallback: the authenticated user's single active membership.
        if ($user instanceof User) {
            $membership = $this->clubUserRepository->findOneBy([
                'userId' => $user->getId(),
                'isActive' => true,
            ]);
            if (null !== $membership) {
                return $membership->getClubId();
            }
        }

        return null;
    }

    private function resolveExplicitSeasonId(Request $request): ?string
    {
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

    private function resolveSeasonId(Request $request, ?string $clubId): ?string
    {
        $seasonId = $this->resolveExplicitSeasonId($request);
        if (null !== $seasonId) {
            return $seasonId;
        }

        // Fallback: the club's single active season (RLS: requires the GUC —
        // only call this after TenantConnectionContext::setClubId()).
        if (null !== $clubId) {
            $season = $this->seasonRepository->findOneBy([
                'clubId' => $clubId,
                'status' => 'active',
            ]);
            if (null !== $season) {
                return $season->getId();
            }
        }

        return null;
    }
}
