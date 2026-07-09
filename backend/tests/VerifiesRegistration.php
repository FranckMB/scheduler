<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Service\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Post-A3, /api/register returns a neutral 202 and never a JWT — the token is
 * issued only by /api/register/verify (which also materialises the club). Tests
 * that need an authenticated, club-owning session must therefore register AND
 * verify. The emailed link is unavailable in tests, so the raw token is re-minted
 * through EmailVerifier (mirroring how PasswordResetTest re-mints a reset token
 * via ResetPasswordHelper) and posted to the real verify endpoint.
 */
trait VerifiesRegistration
{
    /**
     * Drive /api/register/verify for an already-registered (unverified) account and
     * return the issued JWT. Only the email is needed: the pending club intent is read
     * back from the token that /api/register created for that account.
     */
    private function verifyRegistration(KernelBrowser $client, string $email): string
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => strtolower($email)]);
        \assert($user instanceof User);
        $pending = $em->getRepository(EmailVerificationToken::class)->findOneBy(['user' => $user]);
        \assert($pending instanceof EmailVerificationToken);

        // Re-mint the raw token (the emailed value is unavailable in tests), preserving
        // the club intent register stored, then drive the real verify endpoint.
        $raw = $container->get(EmailVerifier::class)->generateToken($user, $pending->getAra(), $pending->getClubName());
        $em->flush();

        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $client->request('POST', '/api/register/verify', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode(['token' => $raw], \JSON_THROW_ON_ERROR));

        return json_decode((string) $client->getResponse()->getContent(), true)['token'] ?? '';
    }
}
