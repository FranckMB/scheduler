<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\ClubUserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * SEC-07: authorization guard for management-only write endpoints.
 *
 * The tenant listener already proved the caller holds an *active* membership on
 * the current club (spoofed `X-Club-Id` → 403). This guard adds the missing
 * layer for the write choke points that only compared the club id
 * (validate/reopen/set-baseline/manual-edit/generate/reorder/appearance):
 * the membership must be a **management** role (owner/admin).
 *
 * Benign today (every member is `admin`), but it is the boundary that keeps the
 * cockpit closed to the forthcoming non-management `coach` role. Mirrors
 * SeasonAccessGuard: a single injectable choke point instead of the idiom
 * duplicated across controllers.
 *
 * 403 (not 404): membership existence is guaranteed by the listener, so the
 * only failure here is a member whose role is not management.
 */
final class ManagementAccessGuard
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly ClubUserRepository $clubUserRepository,
    ) {}

    public function assertManager(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id');
        if (!\is_string($clubId) || '' === $clubId) {
            $clubId = $request?->headers->get('X-Club-Id');
        }

        $user = $this->security->getUser();
        $membership = ($user instanceof User && \is_string($clubId) && '' !== $clubId)
            ? $this->clubUserRepository->findActiveMembership($user->getId(), $clubId)
            : null;

        if (null === $membership || !$this->clubUserRepository->isManagementRole($membership->getRole())) {
            throw new AccessDeniedHttpException('Management role required.');
        }
    }
}
