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
use App\Dto\FixtureInput;
use App\Entity\Fixture;
use App\State\Processor\FixtureStateProcessor;
use App\State\Provider\FixtureStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'Fixture', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: FixtureInput::class, paginationEnabled: true, paginationItemsPerPage: 100, provider: FixtureStateProvider::class, processor: FixtureStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['seasonId' => 'exact', 'teamId' => 'exact', 'competitionId' => 'exact', 'homeAway' => 'exact', 'status' => 'exact'])]
class FixtureResource
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
    public ?string $competitionId = null;

    #[Groups(['read'])]
    public string $matchDate = '';

    #[Groups(['read'])]
    public string $homeAway = '';

    #[Groups(['read'])]
    public string $opponentLabel = '';

    #[Groups(['read'])]
    public string $status = '';

    #[Groups(['read'])]
    public ?string $venueId = null;

    #[Groups(['read'])]
    public ?string $kickoffTime = null;

    public static function fromEntity(Fixture $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->teamId = $entity->getTeamId();
        $dto->seasonId = $entity->getSeasonId();
        $dto->competitionId = $entity->getCompetitionId();
        $dto->matchDate = $entity->getMatchDate()->format('Y-m-d');
        $dto->homeAway = $entity->getHomeAway()->value;
        $dto->opponentLabel = $entity->getOpponentLabel();
        $dto->status = $entity->getStatus()->value;
        $dto->venueId = $entity->getVenueId();
        $dto->kickoffTime = $entity->getKickoffTime()?->format('H:i');

        return $dto;
    }
}
