<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\State\Provider\ClubStateProvider;
use App\State\Processor\ClubStateProcessor;

use App\Entity\Club;

use App\Dto\ClubInput;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: "Club",
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Put(),
        new Delete(),
    ],
    input: ClubInput::class,
    provider: ClubStateProvider::class,
    processor: ClubStateProcessor::class,
    paginationEnabled: true,
    paginationItemsPerPage: 30,
)]
class ClubResource
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
    public string $name = '';

    #[Groups(['read'])]
    public string $slug = '';

    #[Groups(['read'])]
    public ?int $planId = null;

    #[Groups(['read'])]
    public ?string $billingCycle = null;

    #[Groups(['read'])]
    public ?\DateTimeImmutable $planExpiresAt = null;

    #[Groups(['read'])]
    public int $generationCountSeason = 0;

    #[Groups(['read'])]
    public ?string $schoolZone = null;

    #[Groups(['read'])]
    public string $timezone = '';

    #[Groups(['read'])]
    public string $locale = '';

    #[Groups(['read'])]
    public bool $onboardingCompleted = false;

    #[Groups(['read'])]
    public ?string $ffbbClubCode = null;


    public static function fromEntity(Club $entity): self
    {
        $dto = new self();
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->name = $entity->getName();
        $dto->slug = $entity->getSlug();
        $dto->planId = $entity->getPlanId();
        $dto->billingCycle = $entity->getBillingCycle();
        $dto->planExpiresAt = $entity->getPlanExpiresAt();
        $dto->generationCountSeason = $entity->getGenerationCountSeason();
        $dto->schoolZone = $entity->getSchoolZone();
        $dto->timezone = $entity->getTimezone();
        $dto->locale = $entity->getLocale();
        $dto->onboardingCompleted = $entity->getOnboardingCompleted();
        $dto->ffbbClubCode = $entity->getFfbbClubCode();
        return $dto;
    }
}
