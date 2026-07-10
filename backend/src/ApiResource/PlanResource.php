<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Plan;
use App\State\Provider\PlanStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

// SEC-14: read-only over the tenant API. Plan is the GLOBAL billing catalogue (prices,
// maxTeams, maxGenerations) — a write here would let any club member falsify pricing/
// quotas. Managed out-of-band (fixtures / future super-admin surface), never the tenant API.
#[ApiResource(shortName: 'Plan', operations: [
    new GetCollection,
    new Get,
], paginationEnabled: true, paginationItemsPerPage: 30, provider: PlanStateProvider::class)]
class PlanResource
{
    #[Groups(['read'])]
    public string $id = '';

    #[Groups(['read'])]
    public int $version = 0;

    #[Groups(['read'])]
    public DateTimeImmutable $createdAt;

    #[Groups(['read'])]
    public DateTimeImmutable $updatedAt;

    #[Groups(['read'])]
    public string $name = '';

    #[Groups(['read'])]
    public int $maxTeams = 0;

    #[Groups(['read'])]
    public int $maxVenues = 0;

    #[Groups(['read'])]
    public int $maxGenerations = 0;

    #[Groups(['read'])]
    public string $monthlyPrice = '';

    #[Groups(['read'])]
    public string $annualPrice = '';

    /** @var array<string, mixed> */
    #[Groups(['read'])]
    public array $features = [];

    public static function fromEntity(Plan $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->name = $entity->getName();
        $dto->maxTeams = $entity->getMaxTeams();
        $dto->maxVenues = $entity->getMaxVenues();
        $dto->maxGenerations = $entity->getMaxGenerations();
        $dto->monthlyPrice = $entity->getMonthlyPrice();
        $dto->annualPrice = $entity->getAnnualPrice();
        $dto->features = $entity->getFeatures();

        return $dto;
    }
}
