<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\VerifiesRegistration;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * QW-5: the connected user can edit its own profile (PATCH /api/me) and change
 * its password (POST /api/me/password, current password required). Both act on
 * getUser() only → self-only by construction (SEC-02).
 */
#[Group('phase1')]
#[Group('integration')]
final class ProfileEndpointsTest extends WebTestCase
{
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testUpdateProfileNameIsReflectedByMe(): void
    {
        [$token] = $this->register('PRFA');

        // Name-only change keeps the token valid (identity = email), so /me reflects it.
        $this->patch('/api/me', $token, ['firstName' => 'Nouveau', 'lastName' => 'Nom']);
        self::assertResponseIsSuccessful();

        $me = $this->getJson('/api/me', $token);
        self::assertSame('Nouveau', $me['firstName']);
        self::assertSame('Nom', $me['lastName']);
    }

    public function testUpdateProfileEmailReturnsUpdatedBody(): void
    {
        [$token] = $this->register('PRFH');

        // The PATCH response carries the new email; the old JWT (identity = old
        // email) is intentionally no longer usable afterwards → assert on the body.
        $this->patch('/api/me', $token, ['email' => 'prfh-new@test.fr']);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('prfh-new@test.fr', $body['email']);
    }

    public function testUpdateProfileRejectsTakenEmail(): void
    {
        [$tokenA] = $this->register('PRFB');
        [, $emailB] = $this->register('PRFC');

        $this->patch('/api/me', $tokenA, ['email' => $emailB]);
        self::assertResponseStatusCodeSame(409, 'an already-used email must be rejected');
    }

    public function testUpdateProfileRejectsInvalidEmail(): void
    {
        [$token] = $this->register('PRFD');
        $this->patch('/api/me', $token, ['email' => 'not-an-email']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testChangePasswordRequiresCorrectCurrent(): void
    {
        [$token] = $this->register('PRFE');

        $this->post('/api/me/password', $token, ['currentPassword' => 'wrong-one', 'newPassword' => 'newPassword123!']);
        self::assertResponseStatusCodeSame(400, 'a wrong current password must be rejected');
    }

    public function testChangePasswordRejectsShortNew(): void
    {
        [$token] = $this->register('PRFF');
        $this->post('/api/me/password', $token, ['currentPassword' => 'Password123!', 'newPassword' => 'short']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testChangePasswordSucceedsWithCorrectCurrent(): void
    {
        [$token] = $this->register('PRFG');
        $this->post('/api/me/password', $token, ['currentPassword' => 'Password123!', 'newPassword' => 'Brandnewpass123!']);
        self::assertResponseIsSuccessful();
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /** @param array<string, mixed> $body */
    private function patch(string $uri, string $token, array $body): void
    {
        $this->client->request('PATCH', $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, string $token, array $body): void
    {
        $this->client->request('POST', $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function getJson(string $uri, string $token): array
    {
        $this->client->request('GET', $uri, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * @return array{0: string, 1: string} [token, email]
     */
    private function register(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $email = $suffix . '@test.fr';
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'P', 'lastName' => 'Rofile', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token, 'verification must return a token');

        return [$token, $email];
    }
}
