<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\State\Provider\TeamCoachStateProvider;
use App\State\Processor\TeamCoachStateProcessor;

use App\Entity\TeamCoach;

use App\Dto\TeamCoachInput;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: "TeamCoach",
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Put(),
        new Delete(),
    ],
    input: TeamCoachInput::class,
    provider: TeamCoachStateProvider::class,
    processor: TeamCoachStateProcessor::class,
    paginationEnabled: true,
    paginationItemsPerPage: 30,
)]
class TeamCoachResource
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
    public string $coachId = '';

    #[Groups(['read'])]
    public string $role = '';

    #[Groups(['read'])]
    public bool $isRequired = false;


    public static function fromEntity(TeamCoach $entity): self
    {
        $dto = new self();
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->teamId = $entity->getTeamId();
        $dto->coachId = $entity->getCoachId();
        $dto->role = $entity->getRole();
        $dto->isRequired = $entity->getIsRequired();
        return $dto;
    }
}
