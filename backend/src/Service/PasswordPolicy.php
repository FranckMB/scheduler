<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Password strength policy, enforced server-side everywhere a NEW password is
 * set (register, reset, profile change). The frontend mirrors the rules for
 * live feedback, but this is the authority. Rules: at least 12 characters, at
 * least one uppercase letter, at least one special (non-alphanumeric) character.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    /** Human-readable requirement, reusable in UI/API messages. */
    public const REQUIREMENT_FR = 'Le mot de passe doit faire au moins 12 caractères, avec au moins une majuscule et un caractère spécial.';

    /**
     * @return string|null a French error message when the password is too weak,
     *                     or null when it satisfies the policy
     */
    public function validate(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return self::REQUIREMENT_FR;
        }
        if (1 !== preg_match('/\p{Lu}/u', $password)) {
            return self::REQUIREMENT_FR;
        }
        if (1 !== preg_match('/[^\p{L}\p{N}]/u', $password)) {
            return self::REQUIREMENT_FR;
        }

        return null;
    }

    public function isValid(string $password): bool
    {
        return null === $this->validate($password);
    }
}
