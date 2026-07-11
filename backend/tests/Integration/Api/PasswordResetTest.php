<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

#[Group('phase1')]
#[Group('integration')]
final class PasswordResetTest extends WebTestCase
{
    private static int $ipCounter = 0;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testForgotForKnownEmailCreatesResetRequest(): void
    {
        $this->registerUser('known@reset.fr', 'RST1');

        $this->postJson('/api/password/forgot', ['email' => 'known@reset.fr']);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $this->em->getRepository(ResetPasswordRequest::class)->count([]));
    }

    public function testForgotForUnknownEmailStillReturns200WithoutRequest(): void
    {
        $this->postJson('/api/password/forgot', ['email' => 'nobody@nowhere.fr']);

        // No enumeration: same 200 as a known email, but no reset request row.
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->em->getRepository(ResetPasswordRequest::class)->count([]));
    }

    public function testResetWithValidTokenChangesPassword(): void
    {
        $this->registerUser('change@reset.fr', 'RST2');
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'change@reset.fr']);
        $token = self::getContainer()->get(ResetPasswordHelperInterface::class)->generateResetToken($user)->getToken();

        $this->postJson('/api/password/reset', ['token' => $token, 'password' => 'Brandnewpass1!']);
        self::assertResponseIsSuccessful();

        // The new password now authenticates.
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'change@reset.fr', 'password' => 'Brandnewpass1!',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    public function testResetWithInvalidTokenIsRejected(): void
    {
        $this->postJson('/api/password/reset', ['token' => 'not-a-real-token', 'password' => 'Whatever123!']);

        self::assertResponseStatusCodeSame(400);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function registerUser(string $email, string $ara): void
    {
        $ip = '10.2.' . intdiv(self::$ipCounter, 254) . '.' . (self::$ipCounter % 254 + 1);
        ++self::$ipCounter;
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'Reset', 'lastName' => 'User', 'ara' => $ara, 'club_name' => "Club {$ara}", 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        // Register no longer verifies (A3): mark the account verified so the reset flow's
        // final login is not blocked by the email-verification gate. The reset feature is
        // independent of club materialisation, so no need to drive /register/verify here.
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => strtolower($email)]);
        $user?->setEmailVerifiedAt(new DateTimeImmutable);
        $this->em->flush();
    }

    private function postJson(string $path, array $body): void
    {
        $ip = '10.3.' . intdiv(self::$ipCounter, 254) . '.' . (self::$ipCounter % 254 + 1);
        ++self::$ipCounter;
        $this->client->request('POST', $path, [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
