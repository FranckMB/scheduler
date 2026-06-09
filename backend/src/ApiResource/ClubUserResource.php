<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\State\Provider\ClubUserStateProvider;
use App\State\Processor\ClubUserStateProcessor;

use App\Entity\ClubUser;

use App\Dto\ClubUserInput;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: "ClubUser",
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Put(),
        new Delete(),
    ],
    input: ClubUserInput::class,
    provider: ClubUserStateProvider::class,
    processor: ClubUserStateProcessor::class,
    paginationEnabled: true,
    paginationItemsPerPage: 30,
)]
class ClubUserResource
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
    public string $userId = '';

    #[Groups(['read'])]
    public string $role = '';

    #[Groups(['read'])]
    public \DateTimeImmutable $joinedAt;

    #[Groups(['read'])]
    public bool $isActive = false;


    public static function fromEntity(ClubUser $entity): self
    {
        $dto = new self();
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->userId = $entity->getUserId();
        $dto->role = $entity->getRole();
        $dto->joinedAt = $entity->getJoinedAt();
        $dto->isActive = $entity->getIsActive();
        return $dto;
    }
}
