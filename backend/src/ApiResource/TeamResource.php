<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\State\Provider\TeamStateProvider;
use App\State\Processor\TeamStateProcessor;

use App\Entity\Team;

use App\Dto\TeamInput;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: "Team",
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Put(),
        new Delete(),
    ],
    input: TeamInput::class,
    provider: TeamStateProvider::class,
    processor: TeamStateProcessor::class,
    paginationEnabled: true,
    paginationItemsPerPage: 30,
)]
class TeamResource
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
    public string $sportCategoryId = '';

    #[Groups(['read'])]
    public int $priorityTierId = 0;

    #[Groups(['read'])]
    public string $name = '';

    #[Groups(['read'])]
    public ?string $gender = null;

    #[Groups(['read'])]
    public int $sessionsPerWeek = 0;

    #[Groups(['read'])]
    public ?int $minSessionsOverride = null;

    #[Groups(['read'])]
    public ?int $matchDay = null;

    #[Groups(['read'])]
    public ?string $forcedVenueId = null;

    #[Groups(['read'])]
    public bool $isActive = false;

    #[Groups(['read'])]
    public ?string $parentTeamId = null;

    #[Groups(['read'])]
    public ?string $ffbbTeamId = null;


    public static function fromEntity(Team $entity): self
    {
        $dto = new self();
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->sportCategoryId = $entity->getSportCategoryId();
        $dto->priorityTierId = $entity->getPriorityTierId();
        $dto->name = $entity->getName();
        $dto->gender = $entity->getGender();
        $dto->sessionsPerWeek = $entity->getSessionsPerWeek();
        $dto->minSessionsOverride = $entity->getMinSessionsOverride();
        $dto->matchDay = $entity->getMatchDay();
        $dto->forcedVenueId = $entity->getForcedVenueId();
        $dto->isActive = $entity->getIsActive();
        $dto->parentTeamId = $entity->getParentTeamId();
        $dto->ffbbTeamId = $entity->getFfbbTeamId();
        return $dto;
    }
}
