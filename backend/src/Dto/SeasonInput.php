<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class SeasonInput
{
    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $name = '';

    #[Groups(['write'])]
    public \DateTimeImmutable $startDate;

    #[Groups(['write'])]
    public \DateTimeImmutable $endDate;

    #[Assert\Choice(choices: ["draft", "active", "archived", "closed"])]
    #[Groups(['write'])]
    public string $status = '';

}
