<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\State\Provider\SeasonStateProvider;
use App\State\Processor\SeasonStateProcessor;

use App\Entity\Season;

use App\Dto\SeasonInput;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: "Season",
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Put(),
        new Delete(),
    ],
    input: SeasonInput::class,
    provider: SeasonStateProvider::class,
    processor: SeasonStateProcessor::class,
    paginationEnabled: true,
    paginationItemsPerPage: 30,
)]
class SeasonResource
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
    public \DateTimeImmutable $startDate;

    #[Groups(['read'])]
    public \DateTimeImmutable $endDate;

    #[Groups(['read'])]
    public string $status = '';

    #[Groups(['read'])]
    public ?string $exportPdfUrl = null;

    #[Groups(['read'])]
    public array $transitionData = [];


    public static function fromEntity(Season $entity): self
    {
        $dto = new self();
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->name = $entity->getName();
        $dto->startDate = $entity->getStartDate();
        $dto->endDate = $entity->getEndDate();
        $dto->status = $entity->getStatus();
        $dto->exportPdfUrl = $entity->getExportPdfUrl();
        $dto->transitionData = $entity->getTransitionData();
        return $dto;
    }
}
