<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\State\Provider\ScheduleDiagnosticStateProvider;
use App\State\Processor\ScheduleDiagnosticStateProcessor;

use App\Entity\ScheduleDiagnostic;

use App\Dto\ScheduleDiagnosticInput;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: "ScheduleDiagnostic",
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Put(),
        new Delete(),
    ],
    input: ScheduleDiagnosticInput::class,
    provider: ScheduleDiagnosticStateProvider::class,
    processor: ScheduleDiagnosticStateProcessor::class,
    paginationEnabled: true,
    paginationItemsPerPage: 30,
)]
class ScheduleDiagnosticResource
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
    public string $scheduleId = '';

    #[Groups(['read'])]
    public string $type = '';

    #[Groups(['read'])]
    public string $severity = '';

    #[Groups(['read'])]
    public ?string $teamId = null;

    #[Groups(['read'])]
    public ?string $coachId = null;

    #[Groups(['read'])]
    public ?string $venueId = null;

    #[Groups(['read'])]
    public string $message = '';

    #[Groups(['read'])]
    public array $suggestions = [];


    public static function fromEntity(ScheduleDiagnostic $entity): self
    {
        $dto = new self();
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->scheduleId = $entity->getScheduleId();
        $dto->type = $entity->getType();
        $dto->severity = $entity->getSeverity();
        $dto->teamId = $entity->getTeamId();
        $dto->coachId = $entity->getCoachId();
        $dto->venueId = $entity->getVenueId();
        $dto->message = $entity->getMessage();
        $dto->suggestions = $entity->getSuggestions();
        return $dto;
    }
}
