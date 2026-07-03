<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Group('phase1')]
final class VenueTrainingSlotApiTest extends WebTestCase
{
    use TenantGucTrait;

    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    private EntityManagerInterface $em;

    private UserPasswordHasherInterface $passwordHasher;

    private Club $club;

    private User $user;

    private Season $season;

    public static function capacityCases(): array
    {
        return [
            'capacity 2 rejected when venue cannot split' => [2, false, 422],
            'capacity 2 accepted when venue can split' => [2, true, 201],
            'capacity 1 accepted when venue cannot split' => [1, false, 201],
            'capacity 3 rejected by DTO range' => [3, false, 422],
            'capacity 0 rejected by DTO range' => [0, false, 422],
        ];
    }

    #[DataProvider('capacityCases')]
    public function testCapacityValidation(int $capacity, bool $canSplit, int $expectedStatusCode): void
    {
        $venue = $this->createVenue($canSplit);

        $this->client->request('POST', '/api/venue_training_slots', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'venueId' => $venue->getId(),
            'dayOfWeek' => 1,
            'startTime' => '18:00',
            'durationMinutes' => 90,
            'capacity' => $capacity,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame($expectedStatusCode);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get('security.user_password_hasher');

        $this->club = $this->createClub();
        $this->user = $this->createUser();
        $this->createClubUser($this->club, $this->user);
        $this->season = $this->createSeason($this->club);

        $this->client->loginUser($this->user);
    }

    private function createClub(): Club
    {
        $club = new Club;
        $club->setName('Test Club ' . uniqid());
        $club->setSlug('test-club-' . uniqid());
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);

        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        return $club;
    }

    private function createUser(): User
    {
        $user = new User;
        $user->setEmail('test-' . uniqid() . '@example.com');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, 'password123'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createClubUser(Club $club, User $user): void
    {
        $clubUser = new ClubUser;
        $clubUser->setClubId($club->getId());
        $clubUser->setUserId($user->getId());
        $clubUser->setRole('admin');
        $clubUser->setIsActive(true);

        $this->em->persist($clubUser);
        $this->em->flush();
    }

    private function createSeason(Club $club): Season
    {
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $season->setTransitionData([]);

        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function createVenue(bool $canSplit): Venue
    {
        $venue = new Venue;
        $venue->setClubId($this->club->getId());
        $venue->setSeasonId($this->season->getId());
        $venue->setName('Venue ' . uniqid());
        $venue->setSource('manual');
        $venue->setIsActive(true);
        $venue->setCanSplit($canSplit);

        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }
}
