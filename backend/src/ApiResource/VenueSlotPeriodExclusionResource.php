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
use App\Dto\VenueSlotPeriodExclusionInput;
use App\Entity\VenueSlotPeriodExclusion;
use App\State\Processor\VenueSlotPeriodExclusionStateProcessor;
use App\State\Provider\VenueSlotPeriodExclusionStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Un créneau de saison écarté pour une période. Pas de Put : l'exclusion n'a aucun état
 * à éditer — elle existe (le créneau est écarté) ou pas (il revient). Le créneau
 * saisonnier n'est jamais supprimé.
 */
#[ApiResource(shortName: 'VenueSlotPeriodExclusion', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Delete,
], input: VenueSlotPeriodExclusionInput::class, paginationEnabled: false, provider: VenueSlotPeriodExclusionStateProvider::class, processor: VenueSlotPeriodExclusionStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['schedulePlanId' => 'exact'])]
class VenueSlotPeriodExclusionResource
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
    public string $venueTrainingSlotId = '';

    public static function fromEntity(VenueSlotPeriodExclusion $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->schedulePlanId = $entity->getSchedulePlanId();
        $dto->venueTrainingSlotId = $entity->getVenueTrainingSlotId();

        return $dto;
    }
}
