<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClubUser;
use App\Service\TenantConnectionContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClubUser>
 */
final class ClubUserRepository extends ServiceEntityRepository
{
    /**
     * Roles allowed to manage a club (edit settings, import). 'owner' is the
     * highest role, 'admin' the operational one — both may manage. 'editor'
     * and 'viewer' may not. Single source of truth for the management gate.
     */
    private const MANAGEMENT_ROLES = ['owner', 'admin'];

    public function __construct(ManagerRegistry $registry, private readonly TenantConnectionContext $tenantContext)
    {
        parent::__construct($registry, ClubUser::class);
    }

    /**
     * The caller's active membership in a given club, or null. Centralises the
     * (userId, clubId, isActive) lookup reused by the Club provider/processor
     * and the import controller (audit SEC-01/04).
     */
    public function findActiveMembership(string $userId, string $clubId): ?ClubUser
    {
        return $this->findOneBy([
            'userId' => $userId,
            'clubId' => $clubId,
            'isActive' => true,
        ]);
    }

    /**
     * All club ids the caller is an active member of. Resolved with a raw DBAL
     * query so the Doctrine tenant_filter does not apply: ClubUser owns a
     * club_id, so a filtered ORM query would narrow the result to the single
     * active tenant and hide the caller's other clubs (audit finding on
     * ClubStateProvider). club_user is readable across the tenant boundary by
     * design (membership resolution bootstraps the tenant).
     *
     * SEC-12: the RLS SELECT policy is now scoped whenever the tenant GUC is
     * set, so this deliberately cross-tenant read steps out of the tenant
     * context for the duration of the query — the callers (club listing,
     * account erasure) run with a GUC pointing at ONE club, and the old open
     * policy silently hid that this query crossed the boundary.
     *
     * @return list<string>
     */
    public function findActiveClubIds(string $userId): array
    {
        /** @var list<string> $ids */
        $ids = $this->tenantContext->runWithoutTenant(
            fn (): array => $this->getEntityManager()->getConnection()->fetchFirstColumn(
                'SELECT club_id FROM club_user WHERE user_id = :uid AND is_active = true',
                ['uid' => $userId],
            ),
        );

        return $ids;
    }

    public function isManagementRole(string $role): bool
    {
        return \in_array($role, self::MANAGEMENT_ROLES, true);
    }

    /**
     * Emails of a club's active managers (owner + admin). Raw DBAL like
     * findActiveClubIds so the tenant_filter does not narrow the result — the
     * WHERE club_id already scopes it. Used by the reminder cron (no request
     * tenant context). Editors/viewers and inactive memberships are excluded.
     *
     * @return list<string>
     */
    public function findManagementEmails(string $clubId): array
    {
        $placeholders = implode(', ', array_fill(0, \count(self::MANAGEMENT_ROLES), '?'));

        /** @var list<string> $emails */
        $emails = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT u.email FROM club_user cu JOIN app_user u ON u.id = cu.user_id '
            . 'WHERE cu.club_id = ? AND cu.is_active = true AND cu.role IN (' . $placeholders . ')',
            [$clubId, ...self::MANAGEMENT_ROLES],
        );

        return $emails;
    }
}
