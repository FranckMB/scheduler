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
use App\Dto\ConstraintPeriodOverrideInput;
use App\Entity\ConstraintPeriodOverride;
use App\State\Processor\ConstraintPeriodOverrideStateProcessor;
use App\State\Provider\ConstraintPeriodOverrideStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Period-editable structure: a sparse per-(period, constraint) toggle — the permanent
 * constraint is disabled for the period. No row = the constraint applies as usual. The
 * overlay build reads these; the base plan is never touched.
 */
#[ApiResource(shortName: 'ConstraintPeriodOverride', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: ConstraintPeriodOverrideInput::class, paginationEnabled: false, provider: ConstraintPeriodOverrideStateProvider::class, processor: ConstraintPeriodOverrideStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['calendarEntryId' => 'exact', 'constraintId' => 'exact'])]
class ConstraintPeriodOverrideResource
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
    public string $calendarEntryId = '';

    #[Groups(['read'])]
    public string $constraintId = '';

    #[Groups(['read'])]
    public bool $isActive = true;

    public static function fromEntity(ConstraintPeriodOverride $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->calendarEntryId = $entity->getCalendarEntryId();
        $dto->constraintId = $entity->getConstraintId();
        $dto->isActive = $entity->isActive();

        return $dto;
    }
}
