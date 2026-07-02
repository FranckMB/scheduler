<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Club identity accent: PATCH /api/club/appearance updates accentColor + palette
 * on the caller's club, validating hex, and /api/me exposes them.
 */
#[Group('integration')]
final class ClubAppearanceTest extends WebTestCase
{
    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private Club $club;

    private User $user;

    public function testAccentColourAndPaletteAreWritableAndExposed(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('PATCH', '/api/club/appearance', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['accentColor' => '#e11d48', 'accentPalette' => ['#e11d48', '#1e293b', '#f59e0b']], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('#e11d48', $data['accentColor']);
        self::assertSame(['#e11d48', '#1e293b', '#f59e0b'], $data['accentPalette']);
    }

    public function testInvalidHexIsRejected(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('PATCH', '/api/club/appearance', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['accentColor' => 'rouge'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    public function testLogoUploadAndPublicServe(): void
    {
        $this->client->loginUser($this->user);

        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $tmp = (string) tempnam(sys_get_temp_dir(), 'logo') . '.png';
        file_put_contents($tmp, $png);
        $file = new UploadedFile($tmp, 'logo.png', 'image/png', null, true);

        $this->client->request('POST', '/api/club/logo', [], ['file' => $file], ['HTTP_X-Club-Id' => $this->club->getId()]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $expected = '/api/clubs/' . $this->club->getId() . '/logo';
        self::assertSame($expected, $data['logoUrl']);

        // Served publicly with the right content type.
        $this->client->request('GET', $expected);
        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Accent Test Club');
        $this->club->setSlug('accent-test-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->club->setFfbbClubCode('ACC' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('accent' . $uid . '@test.com');
        $this->user->setFirstName('Accent');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);

        $this->em->flush();

        $cu = new ClubUser;
        $cu->setClubId($this->club->getId());
        $cu->setUserId($this->user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($this->club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $this->em->persist($season);

        $this->em->flush();
    }
}
