<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR — P2-5 E1 (fondateur 2026-07-18), axe *planning lifecycle* : PLANS DE PÉRIODE
 * À LA SEMAINE. Une semaine cochée = une CalendarEntry ENFANT (`parentEntryId`)
 * qui naît avec SON plan par le rail existant (1 entrée = 1 plan, ADR-0002 intact).
 *
 * Ce que ce test verrouille :
 *  1. POST d'un enfant ⇒ son plan naît, fenêtre = la semaine, type hérité ; le plan
 *     de la mère survit (il porte le chemin « d'un bloc ») ;
 *  2. un enfant hérite du TYPE de sa mère (422 sinon) ;
 *  3. un seul niveau : un enfant ne se découpe pas (422) ;
 *  4. exclusivité bloc/semaines : mère déjà générée d'un bloc → pas de découpage (422) ;
 *     mère découpée → pas de génération d'un bloc (409 au POST /api/schedules) ;
 *  5. supprimer la MÈRE emporte ses enfants ET leurs plans (cascade complète) ;
 *  6. les contraintes datées d'une semaine se lisent sur sa MÈRE (héritage).
 */
#[Group('phase1')]
#[Group('integration')]
final class WeekChildEntryTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testPostingAWeekChildBirthsItsOwnPlanOnTheWeekWindow(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Barros en travaux', '2026-11-12', '2026-11-18');

        $childId = $this->postWeekChild($user, $motherId, 'closure', 'Barros en travaux — semaine du 9 nov', '2026-11-09', '2026-11-15');

        $childPlan = $this->planOf($club->getId(), $childId);
        self::assertInstanceOf(SchedulePlan::class, $childPlan, 'la semaine enfant naît avec SON plan (rail 1 entrée = 1 plan)');
        self::assertSame(SchedulePlanType::CLOSURE, $childPlan->getType());
        self::assertSame('2026-11-09', $childPlan->getStartDate()->format('Y-m-d'), 'la fenêtre du plan est la SEMAINE');
        self::assertSame('2026-11-15', $childPlan->getEndDate()->format('Y-m-d'));
        self::assertInstanceOf(SchedulePlan::class, $this->planOf($club->getId(), $motherId), 'le plan de la mère survit (chemin « d’un bloc »)');
    }

    public function testAWeekChildInheritsItsMotherPeriodType(): void
    {
        [$user] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Travaux', '2026-11-12', '2026-11-18');

        $this->postWeekChildExpecting(422, $user, $motherId, 'holiday', 'Mauvais type', '2026-11-09', '2026-11-15');
    }

    public function testAWeekChildCannotItselfBeSplit(): void
    {
        [$user] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Travaux', '2026-11-12', '2026-11-18');
        $childId = $this->postWeekChild($user, $motherId, 'closure', 'Semaine 1', '2026-11-09', '2026-11-15');

        $this->postWeekChildExpecting(422, $user, $childId, 'closure', 'Petit-enfant interdit', '2026-11-09', '2026-11-15');
    }

    public function testAChildWindowMustTouchTheMotherAndNotOverlapASibling(): void
    {
        [$user] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Travaux', '2026-11-12', '2026-11-18');
        $this->postWeekChild($user, $motherId, 'closure', 'Semaine 1', '2026-11-09', '2026-11-15');

        // Hors de la fenêtre mère → 422 (elle hériterait les datées sans raison).
        $this->postWeekChildExpecting(422, $user, $motherId, 'closure', 'Hors mère', '2026-12-07', '2026-12-13');
        // Chevauche la semaine 1 (même partiellement) → 422, pas seulement le même lundi.
        $this->postWeekChildExpecting(422, $user, $motherId, 'closure', 'Chevauche', '2026-11-10', '2026-11-16');
    }

    public function testABlockGeneratedMotherRefusesWeekSplitting(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Travaux déjà adaptés', '2026-11-12', '2026-11-18');
        $motherPlan = $this->planOf($club->getId(), $motherId);
        self::assertInstanceOf(SchedulePlan::class, $motherPlan);

        // Une version « bloc » pend au plan de la mère.
        $this->scopeGucToClub($club->getId());
        $version = new Schedule;
        $version->setClubId($club->getId());
        $version->setSeasonId($season->getId());
        $version->setSchedulePlanId($motherPlan->getId());
        $version->setName('V1 bloc');
        $version->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($version);
        $this->em->flush();

        $this->postWeekChildExpecting(422, $user, $motherId, 'closure', 'Découpage refusé', '2026-11-09', '2026-11-15');
    }

    public function testASplitMotherRefusesBlockGeneration(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Travaux découpés', '2026-11-12', '2026-11-18');
        $this->postWeekChild($user, $motherId, 'closure', 'Semaine 1', '2026-11-09', '2026-11-15');
        $motherPlan = $this->planOf($club->getId(), $motherId);
        self::assertInstanceOf(SchedulePlan::class, $motherPlan);

        // POST d'une version sur le plan BLOC d'une mère découpée → 409, avant même
        // la garde socle (le découpage l'emporte : le travail vit sur les semaines).
        $this->client->request('POST', '/api/schedules', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'V1 bloc interdite',
            'status' => 'DRAFT',
            'schedulePlanId' => $motherPlan->getId(),
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    public function testDeletingTheMotherCascadesToItsWeekChildren(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Travaux', '2026-11-12', '2026-11-18');
        $child1 = $this->postWeekChild($user, $motherId, 'closure', 'Semaine 1', '2026-11-09', '2026-11-15');
        $child2 = $this->postWeekChild($user, $motherId, 'closure', 'Semaine 2', '2026-11-16', '2026-11-22');

        $this->client->request('DELETE', '/api/calendar_entries/' . $motherId, [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(204);

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        foreach ([$motherId, $child1, $child2] as $goneId) {
            self::assertNull($this->em->getConnection()->fetchOne('SELECT 1 FROM calendar_entry WHERE id = :id', ['id' => $goneId]) ?: null, 'l’entrée est supprimée');
            self::assertNull($this->em->getRepository(SchedulePlan::class)->findOneBy(['calendarEntryId' => $goneId]), 'son plan aussi');
        }
    }

    public function testAWeekChildReadsItsMotherDatedConstraints(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Barros fermé', '2026-11-12', '2026-11-18');
        $childId = $this->postWeekChild($user, $motherId, 'closure', 'Semaine 1', '2026-11-09', '2026-11-15');

        // Le FAIT (venue_closed) vit sur la MÈRE — patron du cockpit (useCreateVenueClosure).
        $this->scopeGucToClub($club->getId());
        $venueId = '77777777-7777-4777-8777-777777777777';
        $dated = new Constraint;
        $dated->setClubId($club->getId());
        $dated->setSeasonId($season->getId());
        $dated->setName('Barros fermé');
        $dated->setScope(ConstraintScope::FACILITY);
        $dated->setFamily(ConstraintFamily::FACILITY);
        $dated->setRuleType(ConstraintRuleType::HARD);
        $dated->setScopeTargetId($venueId);
        $dated->setConfig(['type' => 'venue_closed']);
        $dated->setCalendarEntryId($motherId);
        $this->em->persist($dated);
        $this->em->flush();

        // Les impacts d'une SEMAINE se calculent avec les datées de sa mère : le
        // gymnase fermé remonte dans les venueIds de l'enfant.
        $this->client->request('GET', '/api/calendar-entries/' . $childId . '/conflicts', [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertContains($venueId, $payload['venueIds'] ?? [], 'la semaine hérite les datées de sa mère');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function postPeriod(User $user, string $periodType, string $title, string $start, string $end): string
    {
        return $this->postEntry($user, [
            'kind' => 'period',
            'title' => $title,
            'startDate' => $start,
            'endDate' => $end,
            'periodType' => $periodType,
        ]);
    }

    private function postWeekChild(User $user, string $parentId, string $periodType, string $title, string $start, string $end): string
    {
        return $this->postEntry($user, [
            'kind' => 'period',
            'title' => $title,
            'startDate' => $start,
            'endDate' => $end,
            'periodType' => $periodType,
            'parentEntryId' => $parentId,
        ]);
    }

    private function postWeekChildExpecting(int $status, User $user, string $parentId, string $periodType, string $title, string $start, string $end): void
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'title' => $title,
            'startDate' => $start,
            'endDate' => $end,
            'periodType' => $periodType,
            'parentEntryId' => $parentId,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($status);
    }

    /** @param array<string, mixed> $body */
    private function postEntry(User $user, array $body): string
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    private function planOf(string $clubId, string $calendarEntryId): ?SchedulePlan
    {
        $this->scopeGucToClub($clubId);
        $this->em->clear();

        return $this->em->getRepository(SchedulePlan::class)->findOneBy(['calendarEntryId' => $calendarEntryId]);
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function createClubWithSeason(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club semaines');
        $club->setSlug('club-semaines-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('WKC' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('semaines' . $uid . '@test.com');
        $user->setFirstName('Week');
        $user->setLastName('Child');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$user, $club, $season];
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
