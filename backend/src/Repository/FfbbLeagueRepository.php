<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FfbbLeague;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FfbbLeague>
 */
final class FfbbLeagueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FfbbLeague::class);
    }

    public function findByCode(string $code): ?FfbbLeague
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * Race-safe upsert keyed on the unique `code` via a raw INSERT … ON CONFLICT,
     * so two workers populating the same league concurrently never trip the unique
     * index (which would abort the caller's flush). `$update` = overwrite an
     * existing row (explicit refresh) vs leave it untouched (cache-first).
     */
    public function upsert(FfbbLeague $l, bool $update): void
    {
        $onConflict = $update
            ? 'DO UPDATE SET name = EXCLUDED.name, address = EXCLUDED.address, postal_code = EXCLUDED.postal_code, city = EXCLUDED.city, phone = EXCLUDED.phone, email = EXCLUDED.email, logo_url = EXCLUDED.logo_url, fetched_at = EXCLUDED.fetched_at'
            : 'DO NOTHING';
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO ffbb_league (id, code, name, address, postal_code, city, phone, email, logo_url, fetched_at)
             VALUES (:id, :code, :name, :address, :postalCode, :city, :phone, :email, :logoUrl, :fetchedAt)
             ON CONFLICT (code) ' . $onConflict,
            [
                'id' => $l->getId(), 'code' => $l->getCode(), 'name' => $l->getName(),
                'address' => $l->getAddress(), 'postalCode' => $l->getPostalCode(), 'city' => $l->getCity(),
                'phone' => $l->getPhone(), 'email' => $l->getEmail(), 'logoUrl' => $l->getLogoUrl(),
                'fetchedAt' => $l->getFetchedAt()->format('Y-m-d H:i:sP'),
            ],
        );
    }
}
