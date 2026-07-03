<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\TeamInput;
use App\Entity\Team;
use App\Enum\Gender;
use App\Enum\TeamLevel;
use App\State\Processor\TeamStateProcessor;
use App\State\Provider\TeamStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'Team', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: TeamInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: TeamStateProvider::class, processor: TeamStateProcessor::class)]
#[ApiFilter(BooleanFilter::class, properties: ['isActive'])]
#[ApiFilter(SearchFilter::class, properties: ['seasonId' => 'exact'])]
class TeamResource
{
    #[Groups(['read'])]
    public string $id = '';

    #[Groups(['read'])]
    public int $version = 0;

    #[Groups(['read'])]
    public DateTimeImmutable $createdAt;

    #[Groups(['read'])]
    public DateTimeImmutable $updatedAt;

    #[Groups(['read'])]
    public string $sportCategoryId = '';

    #[Groups(['read'])]
    public int $priorityTierId = 0;

    #[Groups(['read'])]
    public int $tierOrder = 0;

    #[Groups(['read'])]
    public string $name = '';

    #[Groups(['read'])]
    public ?Gender $gender = null;

    #[Groups(['read'])]
    public ?TeamLevel $level = null;

    #[Groups(['read'])]
    public int $sessionsPerWeek = 0;

    #[Groups(['read'])]
    public ?int $minSessionsOverride = null;

    #[Groups(['read'])]
    public ?int $matchDay = null;

    #[Groups(['read'])]
    public bool $allowMultipleSessionsPerDay = false;

    #[Groups(['read'])]
    public ?string $forcedVenueId = null;

    #[Groups(['read'])]
    public bool $isActive = false;

    #[Groups(['read'])]
    public ?string $parentTeamId = null;

    public static function fromEntity(Team $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->sportCategoryId = $entity->getSportCategoryId();
        $dto->priorityTierId = $entity->getPriorityTierId();
        $dto->tierOrder = $entity->getTierOrder();
        $dto->name = $entity->getName();
        $dto->gender = $entity->getGender();
        $dto->level = $entity->getLevel();
        $dto->sessionsPerWeek = $entity->getSessionsPerWeek();
        $dto->minSessionsOverride = $entity->getMinSessionsOverride();
        $dto->matchDay = $entity->getMatchDay();
        $dto->allowMultipleSessionsPerDay = $entity->getAllowMultipleSessionsPerDay();
        $dto->forcedVenueId = $entity->getForcedVenueId();
        $dto->isActive = $entity->getIsActive();
        $dto->parentTeamId = $entity->getParentTeamId();

        return $dto;
    }
}
