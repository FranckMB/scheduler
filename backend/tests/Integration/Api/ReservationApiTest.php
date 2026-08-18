<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Enum\SeasonStatus;
use App\Tests\CreatesPeriodPlanTrait;
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
    use CreatesPeriodPlanTrait;
    use TenantGucTrait;

    private const VENUE = '22222222-2222-4222-8222-222222222222';

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

        // Base listing (no schedulePlanId) returns the base reservation.
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

    public function testDeletingReservationPurgesItsMaterialisedHardTemplate(): void
    {
        // A reservation gets echoed HARD and materialised by ScheduleResultImporter
        // as a durable ScheduleSlotTemplate. Deleting the reservation must undo the
        // pin — else findBaseSlotTemplates re-injects it forever.
        $start = new DateTimeImmutable('20:30');
        $reservation = (new Reservation)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId('11111111-1111-4111-8111-111111111111')
            ->setVenueId('22222222-2222-4222-8222-222222222222')
            ->setDayOfWeek(2)->setStartTime($start)->setDurationMinutes(120);
        $template = (new ScheduleSlotTemplate)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setScheduleId($this->season->getId())
            ->setTeamId('11111111-1111-4111-8111-111111111111')
            ->setVenueId('22222222-2222-4222-8222-222222222222')
            ->setDayOfWeek(2)->setStartTime($start)->setDurationMinutes(120)
            ->setLockLevel(LockLevel::HARD);
        $this->em->persist($reservation);
        $this->em->persist($template);
        $this->em->flush();
        $templateId = $template->getId();

        $this->client->request('DELETE', '/api/reservations/' . $reservation->getId(), [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($templateId), 'the materialised HARD pin must be purged with the reservation');
    }

    public function testOverlayReservationIsScopedToItsEntry(): void
    {
        $planId = $this->createPeriodPlan($this->club->getId(), $this->season->getId());
        $this->post($planId);
        self::assertResponseStatusCodeSame(201);

        $this->get($planId);
        self::assertCount(1, $this->members());

        // Not visible on the base plan (schedulePlanId IS NULL).
        $this->get(null);
        self::assertCount(0, $this->members());
    }

    // ── P2-37 D3 : on ne réserve pas un gymnase que la période rend indisponible ──

    public function testReservationOnAFullyClosedVenueIsRefused(): void
    {
        // Entrée lun 2026-10-19 → dim 2026-10-25, gymnase fermé sur TOUTE la fenêtre.
        $planId = $this->closedPeriodPlan('2026-10-19', '2026-10-25', '2026-10-19', '2026-10-25', 'Gymnase indisponible');

        $this->post($planId); // dayOfWeek 2 (mardi) — peu importe, tout est fermé
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Gymnase indisponible', (string) $this->client->getResponse()->getContent());
    }

    public function testReservationOnAClosedDayIsRefused(): void
    {
        // Fermeture du SEUL mardi 2026-10-20 (dayOfWeek 2) : le couple (gymnase, mardi) est fermé.
        $planId = $this->closedPeriodPlan('2026-10-19', '2026-10-25', '2026-10-20', '2026-10-20', 'Mardi fermé');

        $this->post($planId); // dayOfWeek 2 = mardi
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Mardi fermé', (string) $this->client->getResponse()->getContent());
    }

    public function testReservationOnAnOpenDayOfAPartiallyClosedVenueSucceeds(): void
    {
        // Même fermeture du mardi, mais on réserve le MERCREDI (dayOfWeek 3) : jour ouvert.
        $planId = $this->closedPeriodPlan('2026-10-19', '2026-10-25', '2026-10-20', '2026-10-20', 'Mardi fermé');

        $this->client->request('POST', '/api/reservations', [], [], $this->headers(), json_encode([
            'teamId' => '11111111-1111-4111-8111-111111111111',
            'venueId' => self::VENUE,
            'dayOfWeek' => 3,
            'startTime' => '20:30',
            'durationMinutes' => 120,
            'schedulePlanId' => $planId,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
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
        $this->season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($this->user);
    }

    /**
     * Un plan de période dont l'entrée porte une fermeture datée du gymnase self::VENUE.
     * Retourne le planId à ancrer sur la réservation.
     */
    private function closedPeriodPlan(string $entryStart, string $entryEnd, string $closureStart, string $closureEnd, string $title): string
    {
        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle($title)
            ->setStartDate(new DateTimeImmutable($entryStart))->setEndDate(new DateTimeImmutable($entryEnd));
        $this->em->persist($entry);
        $constraint = (new Constraint)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName($title)
            ->setScope(ConstraintScope::FACILITY)->setScopeTargetId(self::VENUE)
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)
            ->setCalendarEntryId($entry->getId());
        $constraint->setConfig(['type' => 'venue_closed', 'startDate' => $closureStart, 'endDate' => $closureEnd]);
        $this->em->persist($constraint);
        $this->em->flush();

        return $this->createPeriodPlan($this->club->getId(), $this->season->getId(), $entry->getId());
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

    /** $schedulePlanId : null = réservation de BASE ; set = propre à ce plan (lot C3). */
    private function post(?string $schedulePlanId): string
    {
        $this->client->request('POST', '/api/reservations', [], [], $this->headers(), json_encode([
            'teamId' => '11111111-1111-4111-8111-111111111111',
            'venueId' => '22222222-2222-4222-8222-222222222222',
            'dayOfWeek' => 2,
            'startTime' => '20:30',
            'durationMinutes' => 120,
            'schedulePlanId' => $schedulePlanId,
        ], \JSON_THROW_ON_ERROR));

        return (string) (json_decode((string) $this->client->getResponse()->getContent(), true)['id'] ?? '');
    }

    private function get(?string $schedulePlanId): void
    {
        $query = null !== $schedulePlanId ? '?schedulePlanId=' . $schedulePlanId : '';
        $this->client->request('GET', '/api/reservations' . $query, [], [], $this->headers());
    }

    /** @return array<int, array<string, mixed>> */
    private function members(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $body['member'] ?? $body['hydra:member'] ?? [];
    }
}
