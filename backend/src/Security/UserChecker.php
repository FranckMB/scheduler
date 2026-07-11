<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Gates login on email verification. Runs in checkPostAuth — AFTER the password
 * is verified — and throws the SAME message lexik emits for a wrong password, so
 * an unverified account is indistinguishable from bad credentials (no
 * verification-status oracle; part of A3 anti-enumeration). Wired on the `login`
 * firewall only: a JWT is minted solely by a successful login or by
 * /api/register/verify (which verifies first), so "JWT ⇒ verified" holds at
 * issuance and re-checking on every api request would be redundant.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void {}

    // $token added by symfony/security-core 7.4.14 (UserCheckerInterface change).
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && null === $user->getEmailVerifiedAt()) {
            throw new CustomUserMessageAuthenticationException('Invalid credentials.');
        }
    }
}
