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
use App\Dto\SharedTrainingGroupInput;
use App\Entity\SharedTrainingGroup;
use App\State\Processor\SharedTrainingGroupStateProcessor;
use App\State\Provider\SharedTrainingGroupStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Mutualisation : déclarer que N équipes s'entraînent ENSEMBLE, avec EXACTEMENT
 * ``commonSessions`` séances communes. Scopé club+saison, filtrable par ``schedulePlanId``
 * (NULL = socle saison ; un UUID = plan de période). Écriture réservée au management.
 */
#[ApiResource(shortName: 'SharedTrainingGroup', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: SharedTrainingGroupInput::class, paginationEnabled: false, provider: SharedTrainingGroupStateProvider::class, processor: SharedTrainingGroupStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['schedulePlanId' => 'exact'])]
class SharedTrainingGroupResource
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
    public ?string $schedulePlanId = null;

    /** @var list<string> */
    #[Groups(['read'])]
    public array $teamIds = [];

    #[Groups(['read'])]
    public int $commonSessions = 0;

    /**
     * @param list<string> $teamIds
     */
    public static function fromEntity(SharedTrainingGroup $entity, array $teamIds): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->schedulePlanId = $entity->getSchedulePlanId();
        $dto->teamIds = $teamIds;
        $dto->commonSessions = $entity->getCommonSessions();

        return $dto;
    }
}
