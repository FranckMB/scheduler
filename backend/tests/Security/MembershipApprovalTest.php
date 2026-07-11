<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\VerifiesRegistration;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A pending join request (isActive=false) can only be approved/rejected by an
 * active admin of the SAME club — nobody self-affiliates, no cross-tenant leak.
 */
#[Group('phase1')]
#[Group('integration')]
final class MembershipApprovalTest extends WebTestCase
{
    use VerifiesRegistration;

    private static int $ipCounter = 0;

    private KernelBrowser $client;

    public function testAdminApprovesPendingMemberWhoThenBecomesActive(): void
    {
        $ownerToken = $this->owner('APPR1');
        $joinerToken = $this->joiner('APPR1', 'joiner-appr1@club.fr');

        $pending = $this->authGet('/api/memberships/pending', $ownerToken);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $pending['members']);
        $membershipId = $pending['members'][0]['id'];

        $this->authPost("/api/memberships/{$membershipId}/approve", $ownerToken);
        self::assertResponseIsSuccessful();

        self::assertSame('active', $this->authGet('/api/me', $joinerToken)['membershipStatus']);
    }

    public function testAdminRejectsPendingMember(): void
    {
        $ownerToken = $this->owner('REJ1');
        $joinerToken = $this->joiner('REJ1', 'joiner-rej1@club.fr');

        $membershipId = $this->authGet('/api/memberships/pending', $ownerToken)['members'][0]['id'];
        $this->authPost("/api/memberships/{$membershipId}/reject", $ownerToken);
        self::assertResponseStatusCodeSame(204);

        self::assertSame('none', $this->authGet('/api/me', $joinerToken)['membershipStatus']);
    }

    public function testAdminOfAnotherClubCannotApprove(): void
    {
        $ownerA = $this->owner('CROSSA');
        $this->joiner('CROSSA', 'joiner-crossa@club.fr');
        $membershipId = $this->authGet('/api/memberships/pending', $ownerA)['members'][0]['id'];

        $ownerB = $this->owner('CROSSB');
        $this->authPost("/api/memberships/{$membershipId}/approve", $ownerB);
        self::assertResponseStatusCodeSame(404, 'cross-tenant membership is invisible');
    }

    public function testPendingUserCannotListMemberships(): void
    {
        $this->owner('PEND1');
        $joinerToken = $this->joiner('PEND1', 'joiner-pend1@club.fr');

        $this->authGet('/api/memberships/pending', $joinerToken);
        self::assertResponseStatusCodeSame(403, 'a pending member is not an active admin');
    }

    public function testUnauthenticatedCannotListMemberships(): void
    {
        $this->authGet('/api/memberships/pending', null);
        self::assertResponseStatusCodeSame(401);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /** @param array<string, string> $payload @return array<string, mixed> */
    private function register(array $payload): array
    {
        $ip = '10.1.' . intdiv(self::$ipCounter, 254) . '.' . (self::$ipCounter % 254 + 1);
        ++self::$ipCounter;
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode($payload, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, mixed> */
    private function authGet(string $path, ?string $token): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request('GET', $path, [], [], $server);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function authPost(string $path, string $token): void
    {
        $this->client->request('POST', $path, [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
    }

    private function owner(string $ara): string
    {
        $this->register([
            'email' => "owner-{$ara}@club.fr", 'password' => 'Password123!',
            'firstName' => 'Owner', 'lastName' => 'Admin', 'ara' => $ara, 'club_name' => "Club {$ara}", 'consent' => true,
        ]);

        return $this->verifyRegistration($this->client, "owner-{$ara}@club.fr");
    }

    private function joiner(string $ara, string $email): string
    {
        $this->register([
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'Join', 'lastName' => 'Er', 'ara' => $ara, 'club_name' => 'x', 'consent' => true,
        ]);

        return $this->verifyRegistration($this->client, $email);
    }
}
