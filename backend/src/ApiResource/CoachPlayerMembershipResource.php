<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\State\Provider\CoachPlayerMembershipStateProvider;
use App\State\Processor\CoachPlayerMembershipStateProcessor;

use App\Entity\CoachPlayerMembership;

use App\Dto\CoachPlayerMembershipInput;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: "CoachPlayerMembership",
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Put(),
        new Delete(),
    ],
    input: CoachPlayerMembershipInput::class,
    provider: CoachPlayerMembershipStateProvider::class,
    processor: CoachPlayerMembershipStateProcessor::class,
    paginationEnabled: true,
    paginationItemsPerPage: 30,
)]
class CoachPlayerMembershipResource
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
    public string $teamId = '';

    #[Groups(['read'])]
    public ?string $position = null;

    #[Groups(['read'])]
    public bool $isActive = false;


    public static function fromEntity(CoachPlayerMembership $entity): self
    {
        $dto = new self();
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->coachId = $entity->getCoachId();
        $dto->teamId = $entity->getTeamId();
        $dto->position = $entity->getPosition();
        $dto->isActive = $entity->getIsActive();
        return $dto;
    }
}
