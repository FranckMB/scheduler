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

/**
 * BW2 — Coach.isEmployee must be writable via the API (the recap counts salaried
 * coaches). It used to exist on the entity but be dropped by the input DTO.
 */
#[Group('integration')]
final class CoachApiTest extends WebTestCase
{
    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private Club $club;

    private User $user;

    public function testIsEmployeeIsWritable(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/api/coaches', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['firstName' => 'Jean', 'lastName' => 'Dupont', 'isEmployee' => true], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['isEmployee']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Coach Test Club');
        $this->club->setSlug('coach-test-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->club->setFfbbClubCode('CCH' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('coach' . $uid . '@test.com');
        $this->user->setFirstName('Coach');
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
