<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\CalendarEntryInput;
use App\Entity\CalendarEntry;
use App\State\Processor\CalendarEntryStateProcessor;
use App\State\Provider\CalendarEntryStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'CalendarEntry', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: CalendarEntryInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: CalendarEntryStateProvider::class, processor: CalendarEntryStateProcessor::class)]
class CalendarEntryResource
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
    public string $kind = '';

    #[Groups(['read'])]
    public string $title = '';

    #[Groups(['read'])]
    public string $startDate = '';

    #[Groups(['read'])]
    public string $endDate = '';

    #[Groups(['read'])]
    public bool $isDisruptive = false;

    #[Groups(['read'])]
    public ?string $periodType = null;

    #[Groups(['read'])]
    public ?string $schoolHolidayId = null;

    #[Groups(['read'])]
    public string $status = '';

    #[Groups(['read'])]
    public ?string $overlayScheduleId = null;

    #[Groups(['read'])]
    public ?string $createdBy = null;

    public static function fromEntity(CalendarEntry $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->kind = $entity->getKind()->value;
        $dto->title = $entity->getTitle();
        $dto->startDate = $entity->getStartDate()->format('Y-m-d');
        $dto->endDate = $entity->getEndDate()->format('Y-m-d');
        $dto->isDisruptive = $entity->getIsDisruptive();
        $dto->periodType = $entity->getPeriodType()?->value;
        $dto->schoolHolidayId = $entity->getSchoolHolidayId();
        $dto->status = $entity->getStatus()->value;
        $dto->overlayScheduleId = $entity->getOverlayScheduleId();
        $dto->createdBy = $entity->getCreatedBy();

        return $dto;
    }
}
