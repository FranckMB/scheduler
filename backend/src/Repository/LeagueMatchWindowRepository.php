<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LeagueMatchWindow;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LeagueMatchWindow>
 */
final class LeagueMatchWindowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeagueMatchWindow::class);
    }

    public function findOneByNaturalKey(string $league, string $category, string $level, ?string $gender, int $dayOfWeek, DateTimeImmutable $kickoffMin): ?LeagueMatchWindow
    {
        return $this->findOneBy([
            'league' => $league,
            'category' => $category,
            'level' => $level,
            'gender' => $gender,
            'dayOfWeek' => $dayOfWeek,
            'kickoffMin' => $kickoffMin,
        ]);
    }

    /**
     * The league a club effectively inherits: its own if catalogued, else the
     * FEDERATION default (AURA). Single home of the fallback rule.
     */
    public function effectiveLeague(?string $league): string
    {
        if (null !== $league && [] !== $this->findBy(['league' => $league])) {
            return $league;
        }

        return LeagueMatchWindow::FEDERATION_DEFAULT_LEAGUE;
    }

    /**
     * The envelope a club inherits (windows of its effective league), ordered
     * category → level → day → kickoff for stable display.
     *
     * @return list<LeagueMatchWindow>
     */
    public function findEnvelopeForLeague(?string $league): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.league = :league')
            ->setParameter('league', $this->effectiveLeague($league))
            ->orderBy('w.category', 'ASC')
            ->addOrderBy('w.level', 'ASC')
            ->addOrderBy('w.dayOfWeek', 'ASC')
            ->addOrderBy('w.kickoffMin', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
