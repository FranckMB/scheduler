<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailVerificationToken;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailVerificationToken>
 */
class EmailVerificationTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailVerificationToken::class);
    }

    public function findOneByHashedToken(string $hashedToken): ?EmailVerificationToken
    {
        return $this->findOneBy(['hashedToken' => $hashedToken]);
    }

    /** Drop any outstanding token(s) for a user (one live token at a time). */
    public function deleteForUser(User $user): void
    {
        $this->createQueryBuilder('t')
            ->delete()
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /** @return list<EmailVerificationToken> */
    public function findExpiredBefore(DateTimeImmutable $threshold): array
    {
        /** @var list<EmailVerificationToken> $rows */
        $rows = $this->createQueryBuilder('t')
            ->where('t.expiresAt <= :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
