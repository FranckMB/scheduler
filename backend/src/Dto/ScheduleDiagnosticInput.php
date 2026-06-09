<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ScheduleDiagnosticInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $scheduleId = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $type = null;

    #[Assert\Choice(choices: ['info', 'warning', 'error', 'critical'])]
    #[Groups(['write'])]
    public ?string $severity = null;

    #[Groups(['write'])]
    public ?string $teamId = null;

    #[Groups(['write'])]
    public ?string $coachId = null;

    #[Groups(['write'])]
    public ?string $venueId = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $message = null;

    /** @var array<string, mixed>|null */
    #[Groups(['write'])]
    public ?array $suggestions = null;
}
