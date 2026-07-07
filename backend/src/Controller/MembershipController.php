<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ClubUser;
use App\Entity\User;
use App\Repository\ClubUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Club membership approval — an active admin approves/rejects pending join
 * requests for THEIR club only. A pending membership (isActive=false) is
 * already denied all tenant data by TenantFilterListener; this controls the
 * transition to active.
 */
final class MembershipController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubUserRepository $clubUserRepository,
    ) {}

    #[Route('/api/memberships/pending', name: 'api_memberships_pending', methods: ['GET'])]
    public function pending(): JsonResponse
    {
        $adminMembership = $this->requireActiveAdmin();
        if ($adminMembership instanceof JsonResponse) {
            return $adminMembership;
        }

        $pending = $this->clubUserRepository->findBy([
            'clubId' => $adminMembership->getClubId(),
            'isActive' => false,
        ]);

        $items = [];
        foreach ($pending as $membership) {
            $user = $this->entityManager->getRepository(User::class)->find($membership->getUserId());
            if (null === $user) {
                continue;
            }
            $items[] = [
                'id' => $membership->getId(),
                'userId' => $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
            ];
        }

        return $this->json(['members' => $items]);
    }

    #[Route('/api/memberships/{id}/approve', name: 'api_memberships_approve', methods: ['POST'])]
    public function approve(string $id): JsonResponse
    {
        $target = $this->resolveTargetForAdmin($id);
        if ($target instanceof JsonResponse) {
            return $target;
        }

        $target->setIsActive(true);
        $this->entityManager->flush();

        return $this->json(['id' => $target->getId(), 'isActive' => true]);
    }

    #[Route('/api/memberships/{id}/reject', name: 'api_memberships_reject', methods: ['POST'])]
    public function reject(string $id): JsonResponse
    {
        $target = $this->resolveTargetForAdmin($id);
        if ($target instanceof JsonResponse) {
            return $target;
        }

        $this->entityManager->remove($target);
        $this->entityManager->flush();

        return $this->json(null, 204);
    }

    /** @return ClubUser|JsonResponse The acting user's active admin membership, or an error response. */
    private function requireActiveAdmin(): ClubUser|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $membership = $this->clubUserRepository->findOneBy(['userId' => $user->getId(), 'isActive' => true]);
        // isManagementRole (owner|admin), not a hardcoded 'admin' — an owner
        // must be able to approve members too (review note, PR SEC-07).
        if (null === $membership || !$this->clubUserRepository->isManagementRole($membership->getRole())) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        return $membership;
    }

    /** @return ClubUser|JsonResponse The target pending membership within the admin's club, or an error response. */
    private function resolveTargetForAdmin(string $id): ClubUser|JsonResponse
    {
        $adminMembership = $this->requireActiveAdmin();
        if ($adminMembership instanceof JsonResponse) {
            return $adminMembership;
        }

        $target = $this->clubUserRepository->find($id);
        // Never leak cross-tenant: the target must belong to the admin's own club.
        if (null === $target || $target->getClubId() !== $adminMembership->getClubId()) {
            return $this->json(['error' => 'Not found'], 404);
        }

        return $target;
    }
}
