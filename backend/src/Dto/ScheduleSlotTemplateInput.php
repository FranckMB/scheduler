<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ScheduleSlotTemplateInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $scheduleId = '';

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $teamId = '';

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $venueId = '';

    #[Groups(['write'])]
    public ?string $coachId = null;

    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['write'])]
    public int $dayOfWeek = 0;

    #[Groups(['write'])]
    public \DateTimeImmutable $startTime;

    #[Groups(['write'])]
    public int $durationMinutes = 0;

    #[Assert\Choice(choices: ["NONE", "SOFT", "HARD"])]
    #[Groups(['write'])]
    public string $lockLevel = '';

    #[Groups(['write'])]
    public bool $temporaryLock = false;

    #[Groups(['write'])]
    public ?string $temporaryLockFor = null;

    #[Groups(['write'])]
    public ?int $temporaryMinSessionsOverride = null;

    #[Groups(['write'])]
    public ?array $pendingConstraintSuggestion = null;

}
