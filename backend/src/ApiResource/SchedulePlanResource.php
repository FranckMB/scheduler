<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\SchedulePlan;
use App\Enum\SchedulePlanType;
use App\State\Provider\SchedulePlanStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * ADR-0002 Lot A: read-only view of the SchedulePlan model (the named container
 * of a season/period's versions). Exposed for the frontend to consume in Lot B;
 * plans are born by provisioning (season/schedule creation), never via the API,
 * so no Post/Put/Delete here — Lot B adds the lifecycle mutations.
 */
// The ?calendarEntryId / ?type query filters are implemented by the custom
// provider (SchedulePlanStateProvider::applyRequestFilters) — a Doctrine
// #[ApiFilter] would be inert here since the custom provider bypasses the
// Doctrine extensions.
#[ApiResource(shortName: 'SchedulePlan', operations: [
    new GetCollection,
    new Get,
], paginationEnabled: false, provider: SchedulePlanStateProvider::class)]
class SchedulePlanResource
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
    public string $seasonId = '';

    #[Groups(['read'])]
    public SchedulePlanType $type;

    #[Groups(['read'])]
    public string $name = '';

    #[Groups(['read'])]
    public DateTimeImmutable $startDate;

    #[Groups(['read'])]
    public DateTimeImmutable $endDate;

    #[Groups(['read'])]
    public ?string $calendarEntryId = null;

    #[Groups(['read'])]
    public ?string $chosenScheduleId = null;

    public static function fromEntity(SchedulePlan $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->seasonId = $entity->getSeasonId();
        $dto->type = $entity->getType();
        $dto->name = $entity->getName();
        $dto->startDate = $entity->getStartDate();
        $dto->endDate = $entity->getEndDate();
        $dto->calendarEntryId = $entity->getCalendarEntryId();
        $dto->chosenScheduleId = $entity->getChosenScheduleId();

        return $dto;
    }
}
