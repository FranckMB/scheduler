<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\ClubUser;
use App\State\Provider\ClubUserStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

// READ-ONLY by design (A5/A6): membership WRITES are removed here. Creation goes
// through AuthController (register/join → pending) and role/approval through the
// audited MembershipController; exposing generic POST/PUT/DELETE let a management
// member fabricate memberships or demote the last owner, bypassing that flow.
#[ApiResource(shortName: 'ClubUser', operations: [
    new GetCollection,
    new Get,
], paginationEnabled: true, paginationItemsPerPage: 30, provider: ClubUserStateProvider::class)]
class ClubUserResource
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
    public string $userId = '';

    #[Groups(['read'])]
    public string $role = '';

    #[Groups(['read'])]
    public DateTimeImmutable $joinedAt;

    #[Groups(['read'])]
    public bool $isActive = false;

    public static function fromEntity(ClubUser $entity): self
    {
        $dto = new self;
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
