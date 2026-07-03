<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Dto\UserInput;
use App\Entity\User;
use App\State\Processor\UserStateProcessor;
use App\State\Provider\UserStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

// SEC-02: self-only. No GetCollection (email enumeration) and no bare Post
// (registration is /api/register). Get/Put/Delete are restricted to the
// authenticated user's own record in the provider/processor.
#[ApiResource(shortName: 'User', operations: [
    new Get,
    new Put,
    new Delete,
], input: UserInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: UserStateProvider::class, processor: UserStateProcessor::class)]
class UserResource
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
    public string $email = '';

    #[Groups(['read'])]
    public string $firstName = '';

    #[Groups(['read'])]
    public string $lastName = '';

    #[Groups(['read'])]
    public ?DateTimeImmutable $emailVerifiedAt = null;

    public static function fromEntity(User $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->email = $entity->getEmail();
        $dto->firstName = $entity->getFirstName();
        $dto->lastName = $entity->getLastName();
        $dto->emailVerifiedAt = $entity->getEmailVerifiedAt();

        return $dto;
    }
}
