<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ScheduleInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['DRAFT', 'PENDING', 'GENERATING', 'COMPLETED', 'FAILED', 'VALIDATED'])]
    #[Groups(['write'])]
    public ?string $status = null;

    #[Groups(['write'])]
    public ?int $solverSeed = null;

    /** Set at POST to create this schedule as a period overlay (palier B); ignored on PUT. */
    #[Groups(['write'])]
    public ?string $calendarEntryId = null;
}
