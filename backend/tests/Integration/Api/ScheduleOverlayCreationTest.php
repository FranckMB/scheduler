<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ScheduleStatus;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creating a period overlay via POST /api/schedules with schedulePlanId (ADR-0002 C4 —
 * the body names the PERIOD's plan, not the calendar entry): the server resolves the
 * entry from the plan, stamps the inverse link, and guards the target (422 on an
 * unknown/foreign plan). Entry types that carry no plan (event/cutoff) cannot be named.
 */
#[Group('phase1')]
#[Group('integration')]
final class ScheduleOverlayCreationTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    public function testCreateOverlayLinksEntry(): void
    {
        [$user, $club, $season] = $this->seed('OV1');
        $entry = $this->period($club, $season, CalendarEntryPeriodType::CLOSURE);
        // ADR-0002 C4 : POST /api/schedules nomme désormais le PLAN de la période
        // (schedulePlanId), plus l'entrée calendrier. Le plan naît du geste (rejoué ici).
        $planId = $this->planIdOf($entry);

        $this->post($user, $club, ['name' => 'Vacances', 'status' => 'DRAFT', 'schedulePlanId' => $planId]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($planId, $data['schedulePlanId']);

        // Read the entry back through the API (real read path) → inverse link set.
        $this->client->request('GET', "/api/calendar_entries/{$entry->getId()}", [], [], $this->authHeaders($user, $club));
        self::assertResponseIsSuccessful();
        $entryData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($data['id'], $entryData['overlayScheduleId'], 'server must stamp the inverse link');
    }

    public function testHolidayOverlayAllowed(): void
    {
        [$user, $club, $season] = $this->seed('OV2');
        $entry = $this->period($club, $season, CalendarEntryPeriodType::HOLIDAY);

        $this->post($user, $club, ['name' => 'Toussaint', 'status' => 'DRAFT', 'schedulePlanId' => $this->planIdOf($entry)]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testSecondOverlayCreatesNewActiveVersion(): void
    {
        // planning-versions: a period may carry several overlay versions; the
        // second is allowed and becomes the ACTIVE overlay (no more 422).
        [$user, $club, $season] = $this->seed('OV3');
        $entry = $this->period($club, $season, CalendarEntryPeriodType::CLOSURE);
        $planId = $this->planIdOf($entry);

        $this->post($user, $club, ['name' => 'V1', 'status' => 'DRAFT', 'schedulePlanId' => $planId]);
        self::assertResponseStatusCodeSame(201);
        $v1 = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->post($user, $club, ['name' => 'V2', 'status' => 'DRAFT', 'schedulePlanId' => $planId]);
        self::assertResponseStatusCodeSame(201);
        $v2 = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];
        self::assertNotSame($v1, $v2);

        // The newest version is the active overlay of the period.
        $this->client->request('GET', "/api/calendar_entries/{$entry->getId()}", [], [], $this->authHeaders($user, $club));
        $entryData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($v2, $entryData['overlayScheduleId'], 'the newest version is the active overlay');
    }

    public function testEventEntryCarriesNoPlanSoCannotBeOverlaid(): void
    {
        // ADR-0002 C4 : un overlay se crée en NOMMANT le plan d'une période (schedulePlanId).
        // La garde « type overlayable » a migré du DTO vers la NAISSANCE du plan : seuls
        // closure/holiday en portent un. Un ÉVÉNEMENT n'en a aucun — il n'y a donc rien à
        // nommer, et c'est ainsi qu'il ne peut être overlayé (plus de rejet 422 au POST).
        [, $club, $season] = $this->seed('OV4');
        $this->scopeGucToClub($club->getId());
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::EVENT);
        $entry->setTitle('AG');
        $entry->setStartDate(new DateTimeImmutable('2026-05-12'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-12'));
        $this->em->persist($entry);
        $this->em->flush();

        self::assertNull(
            self::getContainer()->get(SchedulePlanProvisioner::class)->provisionPeriodPlan($entry->getId()),
            'un événement ne porte aucun plan — aucun overlay ne peut le nommer',
        );
    }

    public function testCutoffPeriodCarriesNoPlanSoCannotBeOverlaid(): void
    {
        // Idem : un point de coupure (cutoff) ne porte pas de plan — impossible à overlayer.
        [, $club, $season] = $this->seed('OV5');
        $entry = $this->period($club, $season, CalendarEntryPeriodType::CUTOFF);

        self::assertNull(
            self::getContainer()->get(SchedulePlanProvisioner::class)->provisionPeriodPlan($entry->getId()),
            'une période cutoff ne porte aucun plan — aucun overlay ne peut la nommer',
        );
    }

    public function testForeignEntryRejected(): void
    {
        [$userA, $clubA] = $this->seed('OV6');
        [, $clubB, $seasonB] = $this->seed('OV7');
        $entryB = $this->period($clubB, $seasonB, CalendarEntryPeriodType::CLOSURE);
        $planB = $this->planIdOf($entryB); // provisionné dans le contexte du club B

        // Club A tries to overlay club B's plan → invisible under RLS → 422.
        $this->post($userA, $clubA, ['name' => 'X', 'status' => 'DRAFT', 'schedulePlanId' => $planB]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testOverlayRejectedWithoutAChosenSocle(): void
    {
        [$user, $club, $season] = $this->seed('OV9');
        // Le plan redevient un espace de travail → plus de socle en vigueur.
        self::getContainer()->get(SchedulePlanProvisioner::class)
            ->releaseSchedule((string) $this->chosenPlanVersion($season));
        $entry = $this->period($club, $season, CalendarEntryPeriodType::CLOSURE);

        // 409 : « l'état s'y oppose » — la saison n'a pas de calendrier en vigueur.
        // Avant la bascule, deux miroirs donnaient deux refus (422 sans baseline,
        // 409 sans socle) ; il n'y a plus qu'une condition, donc un seul code, et
        // c'est celui du module matchs (même garde, même message actionnable).
        $this->post($user, $club, ['name' => 'X', 'status' => 'DRAFT', 'schedulePlanId' => $this->planIdOf($entry)]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testSchedulePlanIdImmutableOnPut(): void
    {
        [$user, $club, $season] = $this->seed('OV8');
        $entry = $this->period($club, $season, CalendarEntryPeriodType::CLOSURE);
        $planId = $this->planIdOf($entry);
        $this->post($user, $club, ['name' => 'O', 'status' => 'DRAFT', 'schedulePlanId' => $planId]);
        $scheduleId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        // PUT trying to detach the overlay marker must not change it.
        $this->client->request('PUT', "/api/schedules/{$scheduleId}", [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['name' => 'Renamed', 'status' => 'DRAFT', 'schedulePlanId' => null], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        // GET back through the API: the overlay marker is unchanged.
        $this->client->request('GET', "/api/schedules/{$scheduleId}", [], [], $this->authHeaders($user, $club));
        self::assertResponseIsSuccessful();
        $reloaded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($planId, $reloaded['schedulePlanId'], 'overlay marker is immutable on PUT');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get('security.user_password_hasher');
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user, Club $club): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'HTTP_X-Club-Id' => $club->getId(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(User $user, Club $club, array $payload): void
    {
        $this->client->request('POST', '/api/schedules', [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    private function period(Club $club, Season $season, CalendarEntryPeriodType $type): CalendarEntry
    {
        $this->scopeGucToClub($club->getId());
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType($type);
        $entry->setTitle('Période ' . $type->value);
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seed(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('user-' . $tag . '-' . $uid . '@test.com');
        $user->setFirstName('O');
        $user->setLastName('V');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $this->em->persist($season);
        $this->em->flush();

        // Un plan secondaire (overlay de période) n'est autorisé qu'au-dessus d'un
        // socle POINTÉ (inv. 13) — le geste réel est POST /validate, ici son effet.
        $baseline = new Schedule;
        $baseline->setClubId($club->getId());
        $baseline->setSeasonId($season->getId());
        $baseline->setName('Baseline');
        $baseline->setStatus(ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($baseline);

        return [$user, $club, $season];
    }
}
