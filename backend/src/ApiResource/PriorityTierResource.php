<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\PriorityTierInput;
use App\Entity\PriorityTier;
use App\State\Processor\PriorityTierStateProcessor;
use App\State\Provider\PriorityTierStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'PriorityTier', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: PriorityTierInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: PriorityTierStateProvider::class, processor: PriorityTierStateProcessor::class)]
class PriorityTierResource
{
    #[Groups(['read'])]
    public int $id = 0;

    #[Groups(['read'])]
    public int $version = 0;

    #[Groups(['read'])]
    public DateTimeImmutable $createdAt;

    #[Groups(['read'])]
    public DateTimeImmutable $updatedAt;

    #[Groups(['read'])]
    public string $label = '';

    #[Groups(['read'])]
    public string $name = '';

    #[Groups(['read'])]
    public string $color = '';

    #[Groups(['read'])]
    public int $orToolsWeight = 0;

    #[Groups(['read'])]
    public int $defaultMinSessions = 0;

    public static function fromEntity(PriorityTier $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->label = $entity->getLabel();
        $dto->name = $entity->getName();
        $dto->color = $entity->getColor();
        $dto->orToolsWeight = $entity->getOrToolsWeight();
        $dto->defaultMinSessions = $entity->getDefaultMinSessions();

        return $dto;
    }
}
