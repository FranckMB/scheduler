<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\ScheduleDiagnosticInput;
use App\Entity\ScheduleDiagnostic;
use App\Enum\ScheduleDiagnosticSeverity;
use App\State\Processor\ScheduleDiagnosticStateProcessor;
use App\State\Provider\ScheduleDiagnosticStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'ScheduleDiagnostic', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: ScheduleDiagnosticInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: ScheduleDiagnosticStateProvider::class, processor: ScheduleDiagnosticStateProcessor::class)]
class ScheduleDiagnosticResource
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
    public string $scheduleId = '';

    #[Groups(['read'])]
    public string $type = '';

    #[Groups(['read'])]
    public ScheduleDiagnosticSeverity $severity;

    #[Groups(['read'])]
    public ?string $teamId = null;

    #[Groups(['read'])]
    public ?string $coachId = null;

    #[Groups(['read'])]
    public ?string $venueId = null;

    #[Groups(['read'])]
    public string $message = '';

    /** @var array<string, mixed> */
    #[Groups(['read'])]
    public array $suggestions = [];

    public static function fromEntity(ScheduleDiagnostic $entity): self
    {
        $dto = new self;
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
