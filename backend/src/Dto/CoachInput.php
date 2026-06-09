<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class CoachInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $firstName = '';

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $lastName = '';

    #[Assert\Email]
    #[Groups(['write'])]
    public ?string $email = null;

    #[Groups(['write'])]
    public ?string $phone = null;

    #[Groups(['write'])]
    public ?int $maxDaysOverride = null;

    #[Groups(['write'])]
    public bool $maxDaysOverrideConfirmed = false;

    #[Groups(['write'])]
    public ?int $acceptableLateMinutes = null;

    #[Groups(['write'])]
    public bool $isActive = false;

    #[Groups(['write'])]
    public ?string $parentCoachId = null;

}
