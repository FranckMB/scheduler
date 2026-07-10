<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FfbbCommittee;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FfbbCommittee>
 */
final class FfbbCommitteeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FfbbCommittee::class);
    }

    public function findByCode(string $code): ?FfbbCommittee
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * Race-safe upsert keyed on the unique `code` (see FfbbLeagueRepository::upsert).
     */
    public function upsert(FfbbCommittee $c, bool $update): void
    {
        $onConflict = $update
            ? 'DO UPDATE SET league_code = EXCLUDED.league_code, name = EXCLUDED.name, address = EXCLUDED.address, postal_code = EXCLUDED.postal_code, city = EXCLUDED.city, phone = EXCLUDED.phone, email = EXCLUDED.email, logo_url = EXCLUDED.logo_url, fetched_at = EXCLUDED.fetched_at'
            : 'DO NOTHING';
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO ffbb_committee (id, code, league_code, name, address, postal_code, city, phone, email, logo_url, fetched_at)
             VALUES (:id, :code, :leagueCode, :name, :address, :postalCode, :city, :phone, :email, :logoUrl, :fetchedAt)
             ON CONFLICT (code) ' . $onConflict,
            [
                'id' => $c->getId(), 'code' => $c->getCode(), 'leagueCode' => $c->getLeagueCode(), 'name' => $c->getName(),
                'address' => $c->getAddress(), 'postalCode' => $c->getPostalCode(), 'city' => $c->getCity(),
                'phone' => $c->getPhone(), 'email' => $c->getEmail(), 'logoUrl' => $c->getLogoUrl(),
                'fetchedAt' => $c->getFetchedAt()->format('Y-m-d H:i:sP'),
            ],
        );
    }
}
