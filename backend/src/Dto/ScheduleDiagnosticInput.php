<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ScheduleDiagnosticInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $scheduleId = '';

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $type = '';

    #[Assert\Choice(choices: ["info", "warning", "error", "critical"])]
    #[Groups(['write'])]
    public string $severity = '';

    #[Groups(['write'])]
    public ?string $teamId = null;

    #[Groups(['write'])]
    public ?string $coachId = null;

    #[Groups(['write'])]
    public ?string $venueId = null;

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $message = '';

    #[Groups(['write'])]
    public array $suggestions = [];

}
