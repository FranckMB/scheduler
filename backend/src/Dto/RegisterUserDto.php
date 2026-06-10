<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class RegisterUserDto
{
    #[Assert\NotBlank(message: 'Email is required')]
    #[Assert\Email(message: 'Invalid email address')]
    #[Groups(['write'])]
    public ?string $email = null;

    #[Assert\NotBlank(message: 'Password is required')]
    #[Assert\Length(min: 8, minMessage: 'Password must be at least 8 characters')]
    #[Groups(['write'])]
    public ?string $password = null;

    #[Assert\NotBlank(message: 'ARA is required')]
    #[Assert\Regex(pattern: '/^[A-Z0-9]{3,20}$/', message: 'ARA must be 3-20 uppercase alphanumeric characters')]
    #[Groups(['write'])]
    public ?string $ara = null;

    #[Assert\NotBlank(message: 'Club name is required')]
    #[Assert\Length(min: 2, max: 180, minMessage: 'Club name must be at least 2 characters', maxMessage: 'Club name cannot exceed 180 characters')]
    #[Groups(['write'])]
    public ?string $club_name = null;
}
