<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Service\TenantConnectionContext;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
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

    private string $token;

    /** @return array<string, array{0: int, 1: bool, 2: int}> */
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
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
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

    public function testOverlappingSlotIsRejected(): void
    {
        $venue = $this->createVenue(false);
        $this->postSlot($venue->getId(), 1, '17:00', 90); // 17:00–18:30

        // 16:30–18:00 overlaps the 17:00–18:30 window on the same day/venue.
        $this->postSlot($venue->getId(), 1, '16:30', 90);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAdjacentSlotsDoNotOverlap(): void
    {
        $venue = $this->createVenue(false);
        $this->postSlot($venue->getId(), 1, '17:00', 90); // 17:00–18:30

        // 18:30 start is back-to-back, not overlapping → accepted.
        $this->postSlot($venue->getId(), 1, '18:30', 60);
        self::assertResponseStatusCodeSame(201);

        // A different weekday never conflicts.
        $this->postSlot($venue->getId(), 2, '17:00', 90);
        self::assertResponseStatusCodeSame(201);
    }

    public function testCanSplitPersistsOnVenueUpdate(): void
    {
        // Regression: the venue update processor silently dropped canSplit, so
        // toggling "terrain divisible" never persisted (no per-slot capacity, no
        // court splitting at solve time).
        $venue = $this->createVenue(false);

        $this->client->request('PUT', '/api/venues/' . $venue->getId(), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['name' => $venue->getName(), 'source' => 'manual', 'canSplit' => true], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['canSplit'], 'canSplit=true must persist through a venue update');

        // And un-checking must persist too (false !== null guard).
        $this->client->request('PUT', '/api/venues/' . $venue->getId(), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['name' => $venue->getName(), 'source' => 'manual', 'canSplit' => false], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($body['canSplit'], 'canSplit=false must persist (uncheck)');
    }

    public function testPaginationReturnsEveryRowAcrossPages(): void
    {
        // >30 rows (paginationItemsPerPage=30) force a 2nd page. Without a STABLE
        // total order, Postgres reshuffles rows between page requests and rows
        // straddling the boundary are silently dropped by the client's dedupe-paging
        // (the real "Matéo's Wednesday slots vanish" bug). The provider now orders on
        // the UUID PK, so page1 ∪ page2 must return every created slot.
        $venue = $this->createVenue(false);
        // Seed 35 slots via the EM (bypasses the per-user API rate limit + the
        // anti-overlap guard) under the club GUC. CRITICAL: every slot shares the
        // SAME (dayOfWeek, startTime) so the provider's dayOfWeek+startTime order is
        // fully tied — the UUID PK tiebreaker is then the ONLY thing that makes
        // pagination deterministic. Drop the tiebreaker and the 30/5 page split
        // reshuffles → boundary rows are lost, failing the assertion below.
        $guc = self::getContainer()->get(TenantConnectionContext::class);
        $guc->setClubId($this->club->getId());
        try {
            for ($i = 0; $i < 35; ++$i) {
                $slot = (new VenueTrainingSlot)
                    ->setClubId($this->club->getId())
                    ->setSeasonId($this->season->getId())
                    ->setVenueId($venue->getId())
                    ->setDayOfWeek(1)
                    ->setStartTime(new DateTimeImmutable('18:00'))
                    ->setDurationMinutes(90)
                    ->setCapacity(1);
                $this->em->persist($slot);
            }
            $this->em->flush();
        } finally {
            $guc->clear();
        }

        $ids = [];
        foreach ([1, 2] as $page) {
            $this->client->request('GET', '/api/venue_training_slots?page=' . $page, [], [], [
                'HTTP_X-Club-Id' => $this->club->getId(),
                'HTTP_X-Season-Id' => $this->season->getId(),
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            ]);
            $body = json_decode((string) $this->client->getResponse()->getContent(), true);
            /** @var list<array{id: string}> $members */
            $members = \is_array($body) && \is_array($body['member'] ?? null) ? $body['member'] : [];
            foreach ($members as $slot) {
                $ids[] = $slot['id'];
            }
        }

        self::assertCount(35, array_unique($ids), 'every slot must be returned across pages — stable pagination order.');
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

        // Stateless JWT firewall → every request carries a Bearer (loginUser only
        // authenticates the first request; the overlap tests fire several).
        $this->token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user);
    }

    private function postSlot(string $venueId, int $day, string $start, int $duration): void
    {
        $this->client->request('POST', '/api/venue_training_slots', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'venueId' => $venueId,
            'dayOfWeek' => $day,
            'startTime' => $start,
            'durationMinutes' => $duration,
            'capacity' => 1,
        ], \JSON_THROW_ON_ERROR));
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
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, 'Password123!'));

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
