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
    use ReadsJwtCookie;

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

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        // SEC-16 : le jeton a quitté le corps de la réponse — il est dans le cookie.
        $jwt = $this->jwtFromCookie($client);

        // P3-4 : un ARA inconnu ne matérialise plus le club à la vérification — la
        // demande attend l'approbation du club (mail FFBB) ou du superadmin. Les
        // suites qui appellent ce trait testent l'APRÈS-création : on approuve via
        // le relais dev (le même que les e2e), qui est le vrai service d'approbation.
        // Le flux d'approbation lui-même a ses propres NR (ClubApprovalFlowTest).
        if (\is_array($body) && 'club_pending' === ($body['membershipStatus'] ?? null) && '' !== $jwt) {
            $client->request('POST', '/api/dev/approve-club-request', [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt, 'REMOTE_ADDR' => $ip,
            ]);
        }

        return $jwt;
    }
}
