<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ClubUser;
use App\Entity\User;
use App\Tests\VerifiesRegistration;
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
    use VerifiesRegistration;

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
            ['POST', '/api/schedules/' . self::DUMMY_ID . '/regenerate'],
            ['POST', '/api/schedules/' . self::DUMMY_ID . '/generate'],
            ['POST', '/api/teams/reorder'],
            ['PATCH', '/api/club/appearance'],
            // Lot B: FFBB club-info write (same management surface as appearance).
            ['PATCH', '/api/club/info'],
            // Lot C: FFBB import (fills institutional data — management-only).
            ['POST', '/api/club/ffbb-import'],
            // SEC-12: the pre-solve constraint check is part of the cockpit flow.
            ['POST', '/api/constraints/validate'],
            // Each manual-edit action carries its own guard call — pin all three.
            ['POST', '/api/schedule-slots/' . self::DUMMY_ID . '/manual-edit/constraint'],
            ['POST', '/api/schedule-slots/' . self::DUMMY_ID . '/manual-edit/lock'],
            ['POST', '/api/schedule-slots/' . self::DUMMY_ID . '/manual-edit/one-time'],
            // Club branding writes (same surface as /club/appearance).
            ['POST', '/api/club/logo'],
            ['DELETE', '/api/club/logo'],
        ];
    }

    /**
     * The generic API Platform write lane (review finding: the guard on the
     * custom controllers is a door next to an open wall without this). POST
     * bodies are minimal-valid so the request reaches the processor, where the
     * requiresManagementRole() hook must 403 a non-management member.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function apiPlatformWriteEndpoints(): array
    {
        return [
            ['POST', '/api/schedules', '{"name":"t","status":"DRAFT"}'],
            ['POST', '/api/schedule_slot_templates', '{"scheduleId":"' . self::DUMMY_ID . '","teamId":"' . self::DUMMY_ID . '","venueId":"' . self::DUMMY_ID . '","dayOfWeek":1,"startTime":"18:00"}'],
        ];
    }

    /**
     * A5/A6: membership writes are REMOVED from the ClubUser API entirely (only
     * GetCollection/Get remain) — closing self-escalation (POST role=owner),
     * last-owner demotion (PUT isActive=false) and approval-bypass at once.
     * Legitimate flows use AuthController (create) + MembershipController
     * (approve). Every write verb must therefore be 405 Method Not Allowed, for
     * a management member too (it is not a permission gate but a missing route).
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function clubUserWriteVerbs(): array
    {
        return [
            ['POST', '/api/club_users'],
            ['PUT', '/api/club_users/' . self::DUMMY_ID],
            ['DELETE', '/api/club_users/' . self::DUMMY_ID],
        ];
    }

    public function testNonManagementMemberCannotDeleteSchedule(): void
    {
        // DELETE resolves the item through the provider before the processor
        // runs, so a real schedule (same club) is needed to reach the guard.
        [$adminToken, , $clubA] = $this->register('MGE');
        $this->client->request('POST', '/api/schedules', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
            'CONTENT_TYPE' => 'application/ld+json',
        ], '{"name":"todelete","status":"DRAFT"}');
        self::assertResponseStatusCodeSame(201);
        $scheduleId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'] ?? '';
        self::assertNotSame('', $scheduleId);

        $editorToken = $this->addActiveMember($clubA, 'editor');
        $this->client->request('DELETE', '/api/schedules/' . $scheduleId, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken,
        ]);
        self::assertResponseStatusCodeSame(403, 'schedule DELETE must be management-only');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('apiPlatformWriteEndpoints')]
    public function testNonManagementMemberIsForbiddenOnApiPlatformWrites(string $method, string $url, string $body): void
    {
        [, , $clubA] = $this->register('MGC');
        $editorToken = $this->addActiveMember($clubA, 'editor');

        $this->client->request($method, $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken,
            'CONTENT_TYPE' => 'application/ld+json',
        ], $body);

        self::assertResponseStatusCodeSame(403, \sprintf('%s %s must be management-only', $method, $url));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('clubUserWriteVerbs')]
    public function testClubUserWritesAreRemoved(string $method, string $url): void
    {
        // Even a management member gets 405 — the write routes no longer exist.
        [$adminToken] = $this->register('MGF');
        $this->client->request($method, $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
            'CONTENT_TYPE' => 'application/ld+json',
        ], '{"userId":"' . self::DUMMY_ID . '","role":"owner","isActive":true}');

        self::assertResponseStatusCodeSame(405, \sprintf('%s %s must be method-not-allowed (write removed)', $method, $url));
    }

    public function testScheduleCannotBeCreatedWithNonDraftStatus(): void
    {
        // Review finding: POST accepted any status — fabricating a VALIDATED
        // plan without generation. Even a manager gets 409 for non-DRAFT.
        [$adminToken] = $this->register('MGD');

        $this->client->request('POST', '/api/schedules', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
            'CONTENT_TYPE' => 'application/ld+json',
        ], '{"name":"forged","status":"VALIDATED"}');

        self::assertResponseStatusCodeSame(409, 'a schedule must be created as DRAFT only');
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
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
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
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'M', 'lastName' => 'Role', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $me['club']['id']];
    }
}
