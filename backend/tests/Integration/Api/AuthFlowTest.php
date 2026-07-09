<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Tests\VerifiesRegistration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('phase1')]
#[Group('integration')]
final class AuthFlowTest extends WebTestCase
{
    use VerifiesRegistration;

    private static int $ipCounter = 0;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /**
     * THE A3 anti-enumeration guard: register must return a byte-identical 202 (no
     * token) for a fresh email AND an already-registered one, and create nothing the
     * second time — so the response never reveals whether an account exists.
     */
    public function testRegisterReturnsSame202ForFreshAndExistingEmail(): void
    {
        $payload = [
            'email' => 'enum@club.fr', 'password' => 'Password123!',
            'firstName' => 'En', 'lastName' => 'Um', 'ara' => 'ENUM1', 'club_name' => 'Enum Club',
        ];

        [$freshStatus, $freshBody] = $this->register($payload);
        self::assertSame(202, $freshStatus);
        self::assertArrayNotHasKey('token', $freshBody);
        self::assertSame(1, $this->em->getRepository(User::class)->count(['email' => 'enum@club.fr']));

        // Same email again (different ARA to prove the club path is irrelevant): identical
        // status + body, and no second account nor a materialised club.
        $payload['ara'] = 'ENUM2';
        [$takenStatus, $takenBody] = $this->register($payload);
        self::assertSame(202, $takenStatus);
        self::assertSame($freshBody, $takenBody, 'fresh and taken email must be indistinguishable');
        self::assertSame(1, $this->em->getRepository(User::class)->count(['email' => 'enum@club.fr']), 'no duplicate account');
        self::assertNull($this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => 'ENUM2']), 'taken-email branch creates no club');
    }

    /** Register creates the unverified account + a verification token, but no club yet. */
    public function testRegisterDefersClubCreationUntilVerification(): void
    {
        $this->register([
            'email' => 'defer@club.fr', 'password' => 'Password123!',
            'firstName' => 'De', 'lastName' => 'Fer', 'ara' => 'DEFER1', 'club_name' => 'Defer Club',
        ]);

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'defer@club.fr']);
        self::assertNotNull($user);
        self::assertNull($user->getEmailVerifiedAt(), 'account starts unverified');
        self::assertGreaterThan(0, $this->em->getRepository(EmailVerificationToken::class)->count([]));
        self::assertNull($this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => 'DEFER1']), 'club is not created before verification');
    }

    /** An unverified account cannot log in, and the rejection is indistinguishable from a wrong password. */
    public function testUnverifiedUserCannotLogInAndLooksLikeBadCredentials(): void
    {
        $this->register([
            'email' => 'unverified@club.fr', 'password' => 'Password123!',
            'firstName' => 'Un', 'lastName' => 'Verified', 'ara' => 'UNVER1', 'club_name' => 'Unver Club',
        ]);

        [$unverifiedStatus, $unverifiedBody] = $this->login('unverified@club.fr', 'Password123!');
        [$wrongPwStatus, $wrongPwBody] = $this->login('unverified@club.fr', 'WrongPassword9!');

        self::assertSame(401, $unverifiedStatus);
        self::assertSame($wrongPwStatus, $unverifiedStatus, 'unverified login uses the same status as a wrong password');
        self::assertSame($wrongPwBody, $unverifiedBody, 'unverified login is byte-identical to a wrong password (no oracle)');
    }

    /** Verify materialises the club (new ARA → active admin), issues a JWT, and unlocks login. */
    public function testVerifyNewAraCreatesActiveAdminAndLogsIn(): void
    {
        $this->register([
            'email' => 'admin@newclub.fr', 'password' => 'Password123!',
            'firstName' => 'Jean', 'lastName' => 'Dupont', 'ara' => 'NEWARA1', 'club_name' => 'New Club',
        ]);

        $token = $this->verifyRegistration($this->client, 'admin@newclub.fr');
        self::assertNotEmpty($token, 'verification issues the JWT');
        self::assertNotNull($this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => 'NEWARA1']));

        // Login now succeeds (account verified).
        [$loginStatus] = $this->login('admin@newclub.fr', 'Password123!');
        self::assertSame(200, $loginStatus);
    }

    public function testVerifyDerivesSchoolZoneFromFfbbCode(): void
    {
        // Real FFBB shape: GES (Grand Est) + 0067 (Bas-Rhin) → Strasbourg, zone B.
        $this->register([
            'email' => 'zone@club.fr', 'password' => 'Password123!',
            'firstName' => 'Zoe', 'lastName' => 'Ne', 'ara' => 'GES0067060', 'club_name' => 'Zone Club',
        ]);
        $this->verifyRegistration($this->client, 'zone@club.fr');

        $club = $this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => 'GES0067060']);
        self::assertSame('B', $club?->getSchoolZone());
    }

    public function testVerifyExistingAraCreatesPendingMembership(): void
    {
        // First account verifies → creates the club (active admin).
        $this->register([
            'email' => 'owner@club.fr', 'password' => 'Password123!',
            'firstName' => 'Owner', 'lastName' => 'One', 'ara' => 'EXIST1', 'club_name' => 'Existing Club',
        ]);
        $this->verifyRegistration($this->client, 'owner@club.fr');

        // Second account on the same ARA → pending membership, no new club.
        $this->register([
            'email' => 'joiner@club.fr', 'password' => 'Password123!',
            'firstName' => 'Joiner', 'lastName' => 'Two', 'ara' => 'EXIST1', 'club_name' => 'ignored',
        ]);
        $data = $this->verifyRegistration($this->client, 'joiner@club.fr');
        self::assertNotEmpty($data);

        $club = $this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => 'EXIST1']);
        self::assertCount(1, $this->em->getRepository(Club::class)->findBy(['ffbbClubCode' => 'EXIST1']), 'no duplicate club');

        $memberships = $this->em->getRepository(ClubUser::class)->findBy(['clubId' => $club->getId()]);
        $pending = array_filter($memberships, static fn (ClubUser $m): bool => !$m->getIsActive());
        self::assertCount(1, $pending, 'joiner is a pending membership');
    }

    public function testVerifyWithInvalidTokenIsRejected(): void
    {
        $this->postJson('/api/register/verify', ['token' => 'not-a-real-token']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testRegisterRequiresFirstAndLastName(): void
    {
        [$status] = $this->register([
            'email' => 'noname@club.fr', 'password' => 'Password123!',
            'ara' => 'NONAME1', 'club_name' => 'No Name Club',
        ]);

        self::assertSame(400, $status);
    }

    public function testRegisterRejectsAPasswordBelowPolicy(): void
    {
        // Policy: ≥12 chars, ≥1 uppercase, ≥1 special. "password123" fails all three.
        [$status, $body] = $this->register([
            'email' => 'weakpw@club.fr', 'password' => 'password123',
            'firstName' => 'Weak', 'lastName' => 'Pw', 'ara' => 'WEAKPW1', 'club_name' => 'Weak Club',
        ]);

        self::assertSame(400, $status);
        self::assertStringContainsString('12 caractères', $body['error'] ?? '');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param array<string, string> $payload
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function register(array $payload): array
    {
        $this->request('/api/register', $payload);

        return [
            $this->client->getResponse()->getStatusCode(),
            json_decode((string) $this->client->getResponse()->getContent(), true) ?? [],
        ];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function login(string $email, string $password): array
    {
        $this->request('/api/login', ['email' => $email, 'password' => $password]);

        return [
            $this->client->getResponse()->getStatusCode(),
            json_decode((string) $this->client->getResponse()->getContent(), true) ?? [],
        ];
    }

    /** @param array<string, mixed> $body */
    private function postJson(string $path, array $body): void
    {
        $this->request($path, $body);
    }

    /** @param array<string, mixed> $body */
    private function request(string $path, array $body): void
    {
        // Unique client IP per call so the per-IP auth rate limiters never trip in tests.
        $ip = '10.0.' . intdiv(self::$ipCounter, 254) . '.' . (self::$ipCounter % 254 + 1);
        ++self::$ipCounter;
        $this->client->request('POST', $path, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
