<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ConstraintInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $name = null;

    #[Groups(['write'])]
    public ?string $description = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['CLUB', 'TEAM', 'COACH', 'FACILITY'])]
    #[Groups(['write'])]
    public ?string $scope = null;

    #[Groups(['write'])]
    #[Assert\Uuid(message: 'scopeTargetId must be a valid UUID.')]
    public ?string $scopeTargetId = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['TIME', 'DAY', 'FACILITY', 'COACH_AVAILABILITY', 'FACILITY_CAPACITY'])]
    #[Groups(['write'])]
    public ?string $family = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['HARD', 'PREFERRED', 'BONUS', 'LOCK'])]
    #[Groups(['write'])]
    public ?string $ruleType = null;

    /** @var array<string, mixed>|null */
    #[Groups(['write'])]
    public ?array $config = null;

    #[Groups(['write'])]
    public ?string $createdBy = null;

    #[Groups(['write'])]
    public ?string $source = null;

    #[Groups(['write'])]
    public ?string $sourceOccurrenceId = null;

    /** Set to attach this constraint to a CalendarEntry (period) — excludes it from base generation. */
    #[Groups(['write'])]
    public ?string $calendarEntryId = null;

    #[Groups(['write'])]
    public ?bool $isActive = null;

    #[Groups(['write'])]
    public ?int $sortOrder = null;
}
