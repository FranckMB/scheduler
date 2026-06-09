<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\State\Provider\VenueAvailabilityStateProvider;
use App\State\Processor\VenueAvailabilityStateProcessor;

use App\Entity\VenueAvailability;

use App\Dto\VenueAvailabilityInput;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: "VenueAvailability",
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Put(),
        new Delete(),
    ],
    input: VenueAvailabilityInput::class,
    provider: VenueAvailabilityStateProvider::class,
    processor: VenueAvailabilityStateProcessor::class,
    paginationEnabled: true,
    paginationItemsPerPage: 30,
)]
class VenueAvailabilityResource
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
    public int $dayOfWeek = 0;

    #[Groups(['read'])]
    public \DateTimeImmutable $startTime;

    #[Groups(['read'])]
    public \DateTimeImmutable $endTime;


    public static function fromEntity(VenueAvailability $entity): self
    {
        $dto = new self();
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->venueId = $entity->getVenueId();
        $dto->dayOfWeek = $entity->getDayOfWeek();
        $dto->startTime = $entity->getStartTime();
        $dto->endTime = $entity->getEndTime();
        return $dto;
    }
}
