<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\ScheduleSlotTemplateInput;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
use App\State\Processor\ScheduleSlotTemplateStateProcessor;
use App\State\Provider\ScheduleSlotTemplateStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'ScheduleSlotTemplate', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: ScheduleSlotTemplateInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: ScheduleSlotTemplateStateProvider::class, processor: ScheduleSlotTemplateStateProcessor::class)]
class ScheduleSlotTemplateResource
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
    public string $teamId = '';

    #[Groups(['read'])]
    public string $venueId = '';

    #[Groups(['read'])]
    public ?string $coachId = null;

    #[Groups(['read'])]
    public int $dayOfWeek = 0;

    #[Groups(['read'])]
    public DateTimeImmutable $startTime;

    #[Groups(['read'])]
    public int $durationMinutes = 0;

    #[Groups(['read'])]
    public LockLevel $lockLevel = LockLevel::NONE;

    #[Groups(['read'])]
    public bool $temporaryLock = false;

    #[Groups(['read'])]
    public ?string $temporaryLockFor = null;

    #[Groups(['read'])]
    public ?int $temporaryMinSessionsOverride = null;

    /** @var array<string, mixed>|null */
    #[Groups(['read'])]
    public ?array $pendingConstraintSuggestion = null;

    public static function fromEntity(ScheduleSlotTemplate $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->scheduleId = $entity->getScheduleId();
        $dto->teamId = $entity->getTeamId();
        $dto->venueId = $entity->getVenueId();
        $dto->coachId = $entity->getCoachId();
        $dto->dayOfWeek = $entity->getDayOfWeek();
        $dto->startTime = $entity->getStartTime();
        $dto->durationMinutes = $entity->getDurationMinutes();
        $dto->lockLevel = $entity->getLockLevel();
        $dto->temporaryLock = $entity->getTemporaryLock();
        $dto->temporaryLockFor = $entity->getTemporaryLockFor();
        $dto->temporaryMinSessionsOverride = $entity->getTemporaryMinSessionsOverride();
        $dto->pendingConstraintSuggestion = $entity->getPendingConstraintSuggestion();

        return $dto;
    }
}
