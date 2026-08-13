<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReleaseNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReleaseNote>
 */
final class ReleaseNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReleaseNote::class);
    }

    /**
     * Published notes only (drafts have a null publishedAt), most recent first —
     * what a member sees. No pagination v1.
     *
     * @return list<ReleaseNote>
     */
    public function findPublished(): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.publishedAt IS NOT NULL')
            ->orderBy('n.publishedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every note, drafts included — the super-admin authoring list. Ordered by
     * editorial date (then creation) so a freshly drafted, unpublished note lands
     * where its date says.
     *
     * @return list<ReleaseNote>
     */
    public function findAllForAdmin(): array
    {
        return $this->createQueryBuilder('n')
            ->orderBy('n.noteDate', 'DESC')
            ->addOrderBy('n.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
