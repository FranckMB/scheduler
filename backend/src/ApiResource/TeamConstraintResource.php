<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\TeamConstraintInput;
use App\Entity\TeamConstraint;
use App\State\Processor\TeamConstraintStateProcessor;
use App\State\Provider\TeamConstraintStateProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'TeamConstraint', operations: [
    new GetCollection(),
    new Get(),
    new Post(),
    new Put(),
    new Delete(),
], input: TeamConstraintInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: TeamConstraintStateProvider::class, processor: TeamConstraintStateProcessor::class)]
class TeamConstraintResource
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
    public string $teamId = '';

    #[Groups(['read'])]
    public string $type = '';

    #[Groups(['read'])]
    public ?int $dayOfWeek = null;

    #[Groups(['read'])]
    public ?\DateTimeImmutable $startTime = null;

    #[Groups(['read'])]
    public ?\DateTimeImmutable $endTime = null;

    #[Groups(['read'])]
    public ?string $venueId = null;

    #[Groups(['read'])]
    public ?string $reason = null;

    #[Groups(['read'])]
    public ?string $createdBy = null;

    #[Groups(['read'])]
    public ?string $sourceOccurrenceId = null;

    #[Groups(['read'])]
    public ?string $severity = null;

    public static function fromEntity(TeamConstraint $entity): self
    {
        $dto = new self();
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->teamId = $entity->getTeamId();
        $dto->type = $entity->getType();
        $dto->dayOfWeek = $entity->getDayOfWeek();
        $dto->startTime = $entity->getStartTime();
        $dto->endTime = $entity->getEndTime();
        $dto->venueId = $entity->getVenueId();
        $dto->reason = $entity->getReason();
        $dto->createdBy = $entity->getCreatedBy();
        $dto->sourceOccurrenceId = $entity->getSourceOccurrenceId();
        $dto->severity = $entity->getSeverity();

        return $dto;
    }
}
