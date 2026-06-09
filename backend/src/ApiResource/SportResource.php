<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\SportInput;
use App\Entity\Sport;
use App\State\Processor\SportStateProcessor;
use App\State\Provider\SportStateProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'Sport', operations: [
    new GetCollection(),
    new Get(),
    new Post(),
    new Put(),
    new Delete(),
], input: SportInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: SportStateProvider::class, processor: SportStateProcessor::class)]
class SportResource
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
    public string $slug = '';

    #[Groups(['read'])]
    public ?string $icon = null;

    #[Groups(['read'])]
    public bool $isActive = false;

    public static function fromEntity(Sport $entity): self
    {
        $dto = new self();
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->name = $entity->getName();
        $dto->slug = $entity->getSlug();
        $dto->icon = $entity->getIcon();
        $dto->isActive = $entity->getIsActive();

        return $dto;
    }
}
