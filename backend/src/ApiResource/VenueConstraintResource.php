<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\VenueConstraintInput;
use App\Entity\VenueConstraint;
use App\State\Processor\VenueConstraintStateProcessor;
use App\State\Provider\VenueConstraintStateProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'VenueConstraint', operations: [
    new GetCollection(),
    new Get(),
    new Post(),
    new Put(),
    new Delete(),
], input: VenueConstraintInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: VenueConstraintStateProvider::class, processor: VenueConstraintStateProcessor::class)]
class VenueConstraintResource
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
    public string $venueId = '';

    #[Groups(['read'])]
    public string $constraintType = '';

    #[Groups(['read'])]
    public string $constraintValue = '';

    public static function fromEntity(VenueConstraint $entity): self
    {
        $dto = new self();
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->venueId = $entity->getVenueId();
        $dto->constraintType = $entity->getConstraintType();
        $dto->constraintValue = $entity->getConstraintValue();

        return $dto;
    }
}
