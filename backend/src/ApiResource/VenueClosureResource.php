<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\VenueClosureInput;
use App\Entity\VenueClosure;
use App\State\Processor\VenueClosureStateProcessor;
use App\State\Provider\VenueClosureStateProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'VenueClosure', operations: [
    new GetCollection(),
    new Get(),
    new Post(),
    new Put(),
    new Delete(),
], input: VenueClosureInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: VenueClosureStateProvider::class, processor: VenueClosureStateProcessor::class)]
class VenueClosureResource
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
    public \DateTimeImmutable $dateStart;

    #[Groups(['read'])]
    public \DateTimeImmutable $dateEnd;

    #[Groups(['read'])]
    public ?string $reason = null;

    public static function fromEntity(VenueClosure $entity): self
    {
        $dto = new self();
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->venueId = $entity->getVenueId();
        $dto->dateStart = $entity->getDateStart();
        $dto->dateEnd = $entity->getDateEnd();
        $dto->reason = $entity->getReason();

        return $dto;
    }
}
