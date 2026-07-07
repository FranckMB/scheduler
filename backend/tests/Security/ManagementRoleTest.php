<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ClubUser;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SEC-07 non-regression: the cockpit write endpoints
 * (validate/reopen/set-baseline/generate/reorder/appearance/manual-edit) require
 * a management membership (owner/admin), not merely an authenticated active
 * member. Benign today (every member is admin) but the boundary that keeps the
 * cockpit closed to the forthcoming non-management `coach` role.
 *
 * The ManagementAccessGuard fires before any entity lookup, so a dummy id is
 * enough: a non-management member is rejected with 403 up front, and a manager
 * falls through to the normal not-found/validation path (never 403).
 */
#[Group('phase1')]
#[Group('integration')]
final class ManagementRoleTest extends WebTestCase
{
    private const DUMMY_ID = '00000000-0000-0000-0000-000000000000';

    private KernelBrowser $client;

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function managementEndpoints(): array
    {
        return [
            ['POST', '/api/schedules/' . self::DUMMY_ID . '/validate'],
            ['POST', '/api/schedules/' . self::DUMMY_ID . '/reopen'],
            ['POST', '/api/schedules/' . self::DUMMY_ID . '/set-baseline'],
            ['POST', '/api/schedules/' . self::DUMMY_ID . '/generate'],
            ['POST', '/api/teams/reorder'],
            ['PATCH', '/api/club/appearance'],
            ['POST', '/api/schedule-slots/' . self::DUMMY_ID . '/manual-edit/constraint'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('managementEndpoints')]
    public function testNonManagementMemberIsForbidden(string $method, string $url): void
    {
        [, , $clubA] = $this->register('MGA');
        $editorToken = $this->addActiveMember($clubA, 'editor');

        $this->client->request($method, $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken,
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        self::assertResponseStatusCodeSame(403, \sprintf('%s %s must be management-only', $method, $url));
    }

    public function testManagerPassesTheGuard(): void
    {
        // Admin (management) → guard passes, falls through to 404 (schedule not
        // found), proving the gate is a role check and not a blanket block.
        [$adminToken] = $this->register('MGB');

        $this->client->request('POST', '/api/schedules/' . self::DUMMY_ID . '/validate', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
        ]);

        self::assertResponseStatusCodeSame(404, 'a manager must pass the role guard');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function addActiveMember(string $clubId, string $role): string
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $uid = substr(md5(uniqid('', true)), 0, 8);
        $user = new User;
        $user->setEmail($role . $uid . '@test.fr');
        $user->setFirstName('N');
        $user->setLastName('Member');
        $user->setPasswordHash($hasher->hashPassword($user, 'password123'));
        $em->persist($user);

        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $em->persist($membership);
        $em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, userId, clubId]
     */
    private function register(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'password123',
            'firstName' => 'M', 'lastName' => 'Role', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara,
        ], \JSON_THROW_ON_ERROR));

        $reg = json_decode((string) $this->client->getResponse()->getContent(), true);
        $token = $reg['token'] ?? '';
        self::assertNotSame('', $token, 'registration must return a token');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $me['club']['id']];
    }
}
