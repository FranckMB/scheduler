<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\State\Provider\CoachUnavailabilityStateProvider;
use App\State\Processor\CoachUnavailabilityStateProcessor;

use App\Entity\CoachUnavailability;

use App\Dto\CoachUnavailabilityInput;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: "CoachUnavailability",
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Put(),
        new Delete(),
    ],
    input: CoachUnavailabilityInput::class,
    provider: CoachUnavailabilityStateProvider::class,
    processor: CoachUnavailabilityStateProcessor::class,
    paginationEnabled: true,
    paginationItemsPerPage: 30,
)]
class CoachUnavailabilityResource
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
    public string $coachId = '';

    #[Groups(['read'])]
    public int $dayOfWeek = 0;

    #[Groups(['read'])]
    public ?\DateTimeImmutable $startTime = null;

    #[Groups(['read'])]
    public ?\DateTimeImmutable $endTime = null;


    public static function fromEntity(CoachUnavailability $entity): self
    {
        $dto = new self();
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->coachId = $entity->getCoachId();
        $dto->dayOfWeek = $entity->getDayOfWeek();
        $dto->startTime = $entity->getStartTime();
        $dto->endTime = $entity->getEndTime();
        return $dto;
    }
}
