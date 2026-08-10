<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailChangeToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailChangeToken>
 */
class EmailChangeTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailChangeToken::class);
    }

    public function findOneByHashedToken(string $hashedToken): ?EmailChangeToken
    {
        return $this->findOneBy(['hashedToken' => $hashedToken]);
    }

    /** Drop any outstanding e-mail-change token(s) for a user (one live link at a time). */
    public function deleteForUser(User $user): void
    {
        $this->createQueryBuilder('t')
            ->delete()
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
