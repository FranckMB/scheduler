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
use App\Dto\CompetitionInput;
use App\Entity\Competition;
use App\State\Processor\CompetitionStateProcessor;
use App\State\Provider\CompetitionStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'Competition', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: CompetitionInput::class, paginationEnabled: true, paginationItemsPerPage: 50, provider: CompetitionStateProvider::class, processor: CompetitionStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['seasonId' => 'exact', 'teamId' => 'exact'])]
class CompetitionResource
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
    public string $teamId = '';

    #[Groups(['read'])]
    public string $seasonId = '';

    #[Groups(['read'])]
    public string $name = '';

    #[Groups(['read'])]
    public string $competitionType = '';

    #[Groups(['read'])]
    public ?string $startDate = null;

    #[Groups(['read'])]
    public ?string $endDate = null;

    public static function fromEntity(Competition $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->teamId = $entity->getTeamId();
        $dto->seasonId = $entity->getSeasonId();
        $dto->name = $entity->getName();
        $dto->competitionType = $entity->getCompetitionType()->value;
        $dto->startDate = $entity->getStartDate()?->format('Y-m-d');
        $dto->endDate = $entity->getEndDate()?->format('Y-m-d');

        return $dto;
    }
}
