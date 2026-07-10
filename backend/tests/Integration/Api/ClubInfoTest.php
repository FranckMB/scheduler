<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot B: PATCH /api/club/info partial-updates the FFBB club fields. (Its SEC-07
 * management gate is covered by ManagementRoleTest's managementEndpoints.).
 */
#[Group('phase1')]
final class ClubInfoTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private string $token;

    public function testPartialUpdatePersistsAndResets(): void
    {
        $this->patch(['correspondentName' => 'Clemence H.', 'contactEmail' => 'contact@bccl.fr', 'mainVenueName' => 'Gymnase Mateo', 'committeeCode' => '0069']);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $reloaded = $this->em->getRepository(Club::class)->find($this->club->getId());
        self::assertSame('Clemence H.', $reloaded?->getCorrespondentName());
        self::assertSame('contact@bccl.fr', $reloaded?->getContactEmail());
        self::assertSame('Gymnase Mateo', $reloaded?->getMainVenueName());
        self::assertSame('0069', $reloaded?->getCommitteeCode());
        // Untouched key stays null (partial).
        self::assertNull($reloaded?->getPresidentName());

        // Empty string resets to null.
        $this->patch(['correspondentName' => '']);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertNull($this->em->getRepository(Club::class)->find($this->club->getId())?->getCorrespondentName());
    }

    public function testInvalidEmailIsRejected(): void
    {
        $this->patch(['presidentEmail' => 'not-an-email']);
        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        self::assertNull($this->em->getRepository(Club::class)->find($this->club->getId())?->getPresidentEmail());
    }

    public function testInvalidSchoolZoneIsRejected(): void
    {
        $this->patch(['schoolZone' => 'ZONE_X']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testNonStringValueIsRejectedWithoutWiping(): void
    {
        // Seed a value, then send a JSON number for the same key: it must 422
        // (not silently wipe the column to null).
        $this->patch(['committeeCode' => '0069']);
        self::assertResponseIsSuccessful();

        $this->patch(['committeeCode' => 69]);
        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        self::assertSame('0069', $this->em->getRepository(Club::class)->find($this->club->getId())?->getCommitteeCode());
    }

    public function testMeExposesOfficerContactsOnlyToManagement(): void
    {
        // Officer personal contacts (president/correspondent phone+email) are
        // personal data: /api/me must expose them to an active manager only,
        // never to a non-management or pending member.
        $this->patch(['presidentEmail' => 'president@bccl.fr']);
        self::assertResponseIsSuccessful();

        $adminMe = $this->me($this->token);
        self::assertSame('president@bccl.fr', $adminMe['club']['presidentEmail'] ?? null, 'a manager sees officer contacts');

        $editorMe = $this->me($this->makeMember('editor', true));
        self::assertArrayNotHasKey('presidentEmail', $editorMe['club'] ?? [], 'a non-management member must not see officer contacts');

        $pendingMe = $this->me($this->makeMember('admin', false));
        self::assertArrayNotHasKey('presidentEmail', $pendingMe['club'] ?? [], 'a pending member must not see officer contacts');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('Info ' . $uid)->setSlug('info-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('info' . $uid . '@test.com')->setFirstName('I')->setLastName('N');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $this->em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /** @return array<string, mixed> */
    private function me(string $token): array
    {
        $this->client->request('GET', '/api/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** Adds a member of the seeded club and returns its Bearer token. */
    private function makeMember(string $role, bool $active): string
    {
        $container = self::getContainer();
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);
        $user = (new User)->setEmail('m' . $uid . '@test.com')->setFirstName('M')->setLastName('B');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole($role)->setIsActive($active));
        $this->em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /** @param array<string, mixed> $body */
    private function patch(array $body): void
    {
        $this->client->request('PATCH', '/api/club/info', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
