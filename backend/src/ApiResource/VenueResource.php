<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\State\Provider\VenueStateProvider;
use App\State\Processor\VenueStateProcessor;

use App\Entity\Venue;

use App\Dto\VenueInput;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: "Venue",
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Put(),
        new Delete(),
    ],
    input: VenueInput::class,
    provider: VenueStateProvider::class,
    processor: VenueStateProcessor::class,
    paginationEnabled: true,
    paginationItemsPerPage: 30,
)]
class VenueResource
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
    public bool $isExternal = false;

    #[Groups(['read'])]
    public ?string $color = null;

    #[Groups(['read'])]
    public ?string $latitude = null;

    #[Groups(['read'])]
    public ?string $longitude = null;

    #[Groups(['read'])]
    public string $source = '';

    #[Groups(['read'])]
    public ?string $externalRef = null;

    #[Groups(['read'])]
    public bool $isActive = false;

    #[Groups(['read'])]
    public ?string $parentVenueId = null;


    public static function fromEntity(Venue $entity): self
    {
        $dto = new self();
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->name = $entity->getName();
        $dto->isExternal = $entity->getIsExternal();
        $dto->color = $entity->getColor();
        $dto->latitude = $entity->getLatitude();
        $dto->longitude = $entity->getLongitude();
        $dto->source = $entity->getSource();
        $dto->externalRef = $entity->getExternalRef();
        $dto->isActive = $entity->getIsActive();
        $dto->parentVenueId = $entity->getParentVenueId();
        return $dto;
    }
}
