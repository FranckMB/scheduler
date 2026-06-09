<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class UserInput
{
    #[Assert\Email]
    #[Groups(['write'])]
    public string $email = '';

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $firstName = '';

    #[Assert\NotBlank]
    #[Groups(['write'])]
    public string $lastName = '';

}
