<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\VenuePeriodOverrideInput;
use App\Entity\VenuePeriodOverride;
use App\State\Processor\VenuePeriodOverrideStateProcessor;
use App\State\Provider\VenuePeriodOverrideStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Réglage sparse par (période, gymnase) : DISABLED (le gymnase ne sert pas) ou BLANK
 * (grille vierge, les créneaux de saison sont ignorés). Pas de ligne = INHERIT, le
 * défaut. L'overlay de période lit ces lignes ; le planning principal reste intact.
 */
#[ApiResource(shortName: 'VenuePeriodOverride', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: VenuePeriodOverrideInput::class, paginationEnabled: false, provider: VenuePeriodOverrideStateProvider::class, processor: VenuePeriodOverrideStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['schedulePlanId' => 'exact', 'venueId' => 'exact'])]
class VenuePeriodOverrideResource
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
    public string $schedulePlanId = '';

    #[Groups(['read'])]
    public string $venueId = '';

    #[Groups(['read'])]
    public string $mode = '';

    public static function fromEntity(VenuePeriodOverride $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->schedulePlanId = $entity->getSchedulePlanId();
        $dto->venueId = $entity->getVenueId();
        $dto->mode = $entity->getMode()->value;

        return $dto;
    }
}
