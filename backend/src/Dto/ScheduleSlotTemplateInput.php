<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\LockLevel;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ScheduleSlotTemplateInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $scheduleId = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $teamId = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $venueId = null;

    #[Groups(['write'])]
    public ?string $coachId = null;

    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['write'])]
    public ?int $dayOfWeek = null;

    #[Groups(['write'])]
    public DateTimeImmutable $startTime;

    #[Groups(['write'])]
    public ?int $durationMinutes = 90;

    #[Groups(['write'])]
    public ?LockLevel $lockLevel = null;

    #[Groups(['write'])]
    public ?bool $temporaryLock = null;

    #[Groups(['write'])]
    public ?string $temporaryLockFor = null;

    #[Groups(['write'])]
    public ?int $temporaryMinSessionsOverride = null;

    /** @var array<string, mixed>|null */
    #[Groups(['write'])]
    public ?array $pendingConstraintSuggestion = null;
}
