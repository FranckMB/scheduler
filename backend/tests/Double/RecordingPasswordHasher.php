<?php

declare(strict_types=1);

namespace App\Tests\Double;

use SensitiveParameter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Pass-through decorator over the real password hasher (wired in services_test.yaml
 * as a decorator of `security.user_password_hasher`) that records how many times
 * hashPassword() was called. It lets PasswordResetEnumerationTest prove the
 * "unknown account" branch of /api/password/forgot still spends a (throwaway) hash —
 * the CPU-cost equaliser that keeps the endpoint from leaking account existence by
 * timing. Everything else is delegated verbatim to the decorated hasher.
 *
 * The counter is static because the hasher is a shared service: the test reads it
 * across the request boundary and resets it in setUp().
 */
final class RecordingPasswordHasher implements UserPasswordHasherInterface
{
    public static int $hashCount = 0;

    public function __construct(
        private readonly UserPasswordHasherInterface $inner,
    ) {}

    public static function reset(): void
    {
        self::$hashCount = 0;
    }

    public function hashPassword(PasswordAuthenticatedUserInterface $user, #[SensitiveParameter] string $plainPassword): string
    {
        ++self::$hashCount;

        return $this->inner->hashPassword($user, $plainPassword);
    }

    public function isPasswordValid(PasswordAuthenticatedUserInterface $user, #[SensitiveParameter] string $plainPassword): bool
    {
        return $this->inner->isPasswordValid($user, $plainPassword);
    }

    public function needsRehash(PasswordAuthenticatedUserInterface $user): bool
    {
        return $this->inner->needsRehash($user);
    }
}
