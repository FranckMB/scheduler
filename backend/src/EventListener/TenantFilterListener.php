<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Doctrine\Filter\TenantFilter;
use App\Entity\User;
use App\Repository\ClubUserRepository;
use App\Repository\SeasonRepository;
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

        $request = $event->getRequest();
        $user = $this->authenticatedUser();

        // Single active club per user: when no explicit tenant is supplied, derive
        // it from the JWT user's active membership. Header/attribute stay as an
        // override (e.g. tests). The active season is derived the same way.
        $clubId = $this->resolveClubId($request, $user);
        $seasonId = $this->resolveSeasonId($request, $clubId);

        if (null !== $seasonId) {
            $request->attributes->set('_season_id', $seasonId);
        }

        if (null !== $clubId) {
            $request->attributes->set('_club_id', $clubId);
        }

        if (null === $clubId) {
            return;
        }

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

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            'SET LOCAL app.club_id = ' . $connection->quote($clubId),
        );
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

    private function resolveSeasonId(Request $request, ?string $clubId): ?string
    {
        $seasonId = $request->attributes->get('_season_id');
        if (\is_string($seasonId) && '' !== $seasonId) {
            return $seasonId;
        }

        $seasonId = $request->headers->get('X-Season-Id');
        if (\is_string($seasonId) && '' !== $seasonId) {
            return $seasonId;
        }

        // Fallback: the club's single active season.
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
