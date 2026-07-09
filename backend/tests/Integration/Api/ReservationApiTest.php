<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('phase1')]
final class ReservationApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private User $user;

    private Season $season;

    private string $token;

    public function testCreateListAndDeleteBaseReservation(): void
    {
        $id = $this->post(null);
        self::assertResponseStatusCodeSame(201);

        // Base listing (no calendarEntryId) returns the base reservation.
        $this->get(null);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->members());

        // A period-overlay listing excludes the base one.
        $this->get('44444444-4444-4444-8444-444444444444');
        self::assertCount(0, $this->members());

        // Delete → gone from the base listing.
        $this->client->request('DELETE', '/api/reservations/' . $id, [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);
        $this->get(null);
        self::assertCount(0, $this->members());
    }

    public function testOverlayReservationIsScopedToItsEntry(): void
    {
        $this->post('33333333-3333-4333-8333-333333333333');
        self::assertResponseStatusCodeSame(201);

        $this->get('33333333-3333-4333-8333-333333333333');
        self::assertCount(1, $this->members());

        // Not visible on the base plan (calendarEntryId IS NULL).
        $this->get(null);
        self::assertCount(0, $this->members());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = (new Club)
            ->setName('Res Club ' . $uid)
            ->setSlug('res-club-' . $uid)
            ->setTimezone('Europe/Paris')
            ->setLocale('fr')
            ->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('res' . $uid . '@test.com');
        $this->user->setFirstName('Res');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'Password123!'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $cu = new ClubUser;
        $cu->setClubId($this->club->getId());
        $cu->setUserId($this->user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName('2025-2026');
        $this->season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $this->season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $this->season->setStatus('active');
        $this->em->persist($this->season);
        $this->em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($this->user);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }

    private function post(?string $calendarEntryId): string
    {
        $this->client->request('POST', '/api/reservations', [], [], $this->headers(), json_encode([
            'teamId' => '11111111-1111-4111-8111-111111111111',
            'venueId' => '22222222-2222-4222-8222-222222222222',
            'dayOfWeek' => 2,
            'startTime' => '20:30',
            'durationMinutes' => 120,
            'calendarEntryId' => $calendarEntryId,
        ], \JSON_THROW_ON_ERROR));

        return (string) (json_decode((string) $this->client->getResponse()->getContent(), true)['id'] ?? '');
    }

    private function get(?string $calendarEntryId): void
    {
        $query = null !== $calendarEntryId ? '?calendarEntryId=' . $calendarEntryId : '';
        $this->client->request('GET', '/api/reservations' . $query, [], [], $this->headers());
    }

    /** @return array<int, array<string, mixed>> */
    private function members(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $body['member'] ?? $body['hydra:member'] ?? [];
    }
}
