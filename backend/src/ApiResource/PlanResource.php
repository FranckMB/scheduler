<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\State\Provider\PlanStateProvider;
use App\State\Processor\PlanStateProcessor;

use App\Entity\Plan;

use App\Dto\PlanInput;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: "Plan",
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Put(),
        new Delete(),
    ],
    input: PlanInput::class,
    provider: PlanStateProvider::class,
    processor: PlanStateProcessor::class,
    paginationEnabled: true,
    paginationItemsPerPage: 30,
)]
class PlanResource
{
    #[Groups(['read'])]
    public string $id = '';

    #[Groups(['read'])]
    public int $version = 0;

    #[Groups(['read'])]
    public \DateTimeImmutable $createdAt;

    #[Groups(['read'])]
    public \DateTimeImmutable $updatedAt;

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

    #[Groups(['read'])]
    public array $features = [];


    public static function fromEntity(Plan $entity): self
    {
        $dto = new self();
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
