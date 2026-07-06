<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Doctrine\Filter\SeasonFilter;
use App\Doctrine\Filter\TenantFilter;
use App\Entity\Season;
use App\Entity\User;
use App\Repository\ClubUserRepository;
use App\Repository\SeasonRepository;
use App\Service\SeasonResolver;
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
        private readonly SeasonResolver $seasonResolver,
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

    private static function isUuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
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
            // No tenant: only an explicit season header/attribute can apply
            // (the current-season fallback needs a club anyway). Ownership
            // cannot be checked without a club, but the UUID shape can — a
            // garbage value must never reach a downstream _season_id consumer.
            $explicitSeason = $this->resolveExplicitSeasonId($request);
            if (null !== $explicitSeason && self::isUuid($explicitSeason)) {
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

        // Season AFTER the GUC: the season lookups query the RLS-protected
        // season table — before the GUC they always found 0 rows and the
        // header-less frontend flow lost its _season_id.
        $seasons = $this->seasonResolver->seasonsForClub($clubId);

        $explicitSeasonId = $this->resolveExplicitSeasonId($request);
        if (null !== $explicitSeasonId) {
            $season = $this->findClubSeason($explicitSeasonId, $clubId);
            // Unknown, malformed or foreign-club season → 403, never a silent
            // fallback (mirror of the spoofed X-Club-Id check above). RLS
            // already hides other clubs' seasons from the lookup.
            if (null === $season) {
                $event->setResponse(new JsonResponse(
                    ['error' => 'You do not have access to this season'],
                    403,
                ));

                return;
            }
            $readonly = SeasonResolver::isReadonlyAmong($season, $seasons);
        } else {
            // Current season derived from the calendar (SeasonResolver) —
            // replaces the historical single `status='active'` lookup. The
            // current season is writable by definition.
            $season = SeasonResolver::currentAmong($seasons);
            $readonly = false;
        }

        if (null !== $season) {
            $request->attributes->set('_season_id', $season->getId());
            $request->attributes->set('_season_readonly', $readonly);

            /** @var SeasonFilter $seasonFilter */
            $seasonFilter = $filterCollection->enable('season_filter');
            $seasonFilter->setParameter('season_id', $season->getId(), 'uuid');
        }
    }

    /**
     * Loads a season by id and checks it belongs to the resolved club.
     * Pre-validates the UUID shape: a malformed id must NOT reach Postgres
     * (an "invalid input syntax for type uuid" error would abort the
     * surrounding transaction under the test harness).
     */
    private function findClubSeason(string $seasonId, string $clubId): ?Season
    {
        if (!self::isUuid($seasonId)) {
            return null;
        }

        $season = $this->seasonRepository->find($seasonId);
        if (null === $season || $season->getClubId() !== $clubId) {
            return null;
        }

        return $season;
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
}
