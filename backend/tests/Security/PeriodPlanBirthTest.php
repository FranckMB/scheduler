<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SchedulePlanType;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR — ADR-0002 lot C, axe *planning lifecycle* : LE PLAN NAÎT DU GESTE.
 *
 * Décision fondateur (2026-07-17) : un plan naît en réponse à un événement du
 * calendrier. Le geste « ajuster une période de vacances / un souci du calendrier »
 * EST la création du `CalendarEntry`, et c'est la SEULE façon de créer un plan
 * CLOSURE/HOLIDAY. Le lot A le créait à la première génération : trop tard, car les
 * réglages de la période (inv. 5) se saisissent AVANT toute version et doivent
 * s'accrocher à un plan existant.
 *
 * Ce que ce test verrouille :
 *  1. créer une période génératrice ⇒ son plan existe AVANT toute génération ;
 *  2. inv. 9 — `cutoff`/`mutualisation` restent des rappels calendrier : AUCUN plan ;
 *  3. le type du plan suit le type de la période ;
 *  4. promouvoir une période non génératrice (PUT) mint son plan — c'est le geste ;
 *  5. un plan par période, jamais deux (le geste rejoué ne duplique pas).
 */
#[Group('phase1')]
#[Group('integration')]
final class PeriodPlanBirthTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testCreatingAHolidayPeriodBirthsItsPlanBeforeAnyGeneration(): void
    {
        [$user, $club] = $this->createClubWithSeason();

        $entryId = $this->postPeriod($user, 'holiday', 'Vacances de Toussaint');

        // Le cœur du lot C : le plan est là alors qu'AUCUN schedule n'existe.
        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan, 'Le geste « ajuster » doit créer le plan de la période.');
        self::assertSame(SchedulePlanType::HOLIDAY, $plan->getType());
        self::assertFalse($plan->isTeamSelectionInitialized(), 'Un plan neuf n’est pas encore configuré (garde de seed).');
        self::assertSame(0, $this->scheduleCount($club->getId()), 'Aucune génération ne doit être nécessaire.');
    }

    public function testCreatingAClosurePeriodBirthsAClosurePlan(): void
    {
        [$user, $club] = $this->createClubWithSeason();

        $entryId = $this->postPeriod($user, 'closure', 'Gymnase en travaux');

        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan);
        self::assertSame(SchedulePlanType::CLOSURE, $plan->getType());
    }

    /**
     * Invariant 9 — « Périodes sans plan » : cutoff/mutualisation restent des rappels
     * calendrier. Leur créer un plan donnerait un espace de travail fantôme, jamais
     * générable, dans le sélecteur.
     */
    public function testNonGeneratingPeriodsCarryNoPlan(): void
    {
        [$user, $club] = $this->createClubWithSeason();

        foreach (['cutoff', 'mutualisation'] as $periodType) {
            $entryId = $this->postPeriod($user, $periodType, 'Rappel ' . $periodType);
            self::assertNull(
                $this->planOf($club->getId(), $entryId),
                \sprintf('Une période « %s » ne porte pas de plan (inv. 9).', $periodType),
            );
        }
    }

    /**
     * Promouvoir un rappel en période génératrice EST le geste « ajuster » : le plan
     * doit naître à ce moment-là, sinon la période resterait inconfigurable pour
     * toujours (plus aucun code ne crée un plan a posteriori).
     */
    public function testPromotingANonGeneratingPeriodBirthsItsPlan(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'cutoff', 'Rappel à promouvoir');
        self::assertNull($this->planOf($club->getId(), $entryId));

        $this->putPeriod($user, $entryId, ['periodType' => 'holiday', 'title' => 'Rappel à promouvoir']);

        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan, 'La promotion est un geste : elle doit créer le plan.');
        self::assertSame(SchedulePlanType::HOLIDAY, $plan->getType());
    }

    /**
     * Un plan par période — garanti par uniq_schedule_plan_calendar_entry ET par
     * l'idempotence de syncPeriodPlan. Un PUT (qui re-provisionne) ne doit pas
     * en faire naître un second : deux plans = deux jeux de réglages divergents.
     */
    public function testTheGestureReplayedDoesNotDuplicateThePlan(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances');

        $this->putPeriod($user, $entryId, ['periodType' => 'holiday', 'title' => 'Vacances renommées']);

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $plans = $this->em->getRepository(SchedulePlan::class)->findBy(['calendarEntryId' => $entryId]);
        self::assertCount(1, $plans, 'Le geste rejoué ne duplique pas le plan.');
    }

    /**
     * NR — inv. 9 tenu DANS LE TEMPS, pas seulement à la naissance. Rétrograder une
     * période génératrice supprime son plan : sans ça, un `cutoff` garderait un plan
     * HOLIDAY vivant, le wizard le verrait non-configuré et seederait des overrides
     * Fanion sur une période qui ne doit rien porter.
     */
    public function testDemotingAGeneratingPeriodDeletesItsPlan(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances finalement coupées');
        self::assertInstanceOf(SchedulePlan::class, $this->planOf($club->getId(), $entryId));

        $this->putPeriod($user, $entryId, ['periodType' => 'cutoff', 'title' => 'Coupure']);

        self::assertNull(
            $this->planOf($club->getId(), $entryId),
            'Une période rétrogradée hors closure/holiday ne porte plus de plan (inv. 9).',
        );
    }

    /**
     * NR — la fenêtre du plan suit celle de sa période. Le plan naissant désormais AVANT
     * toute génération, ses dates seraient figées à la création sans cette
     * synchronisation ; sous le lot A le cas n'existait pas (le plan naissait à la
     * génération, avec les dates du moment).
     */
    public function testEditingThePeriodDatesResyncsThePlanWindow(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances à recaler');

        $this->putPeriod($user, $entryId, [
            'periodType' => 'holiday',
            'title' => 'Vacances à recaler',
            'startDate' => '2027-02-15',
            'endDate' => '2027-02-22',
        ]);

        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan);
        self::assertSame('2027-02-15', $plan->getStartDate()->format('Y-m-d'), 'la fenêtre du plan suit la période');
        self::assertSame('2027-02-22', $plan->getEndDate()->format('Y-m-d'));
    }

    /**
     * NR — le NOM ne se synchronise PAS (inv. 12 : il appartient au plan, seul son
     * renommage l'écrit). Un second écrivain le rendrait non durable.
     */
    public function testRenamingThePeriodDoesNotOverwriteThePlanName(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Nom de naissance');

        $this->putPeriod($user, $entryId, ['periodType' => 'holiday', 'title' => 'Titre changé']);

        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan);
        self::assertSame('Nom de naissance', $plan->getName(), 'le nom du plan ne suit pas le titre de la période (inv. 12)');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function postPeriod(User $user, string $periodType, string $title): string
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'title' => $title,
            'startDate' => '2026-10-19',
            'endDate' => '2026-11-02',
            'periodType' => $periodType,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    /**
     * PUT = remplacement complet : kind/startDate/endDate sont NotBlank, on renvoie
     * donc l'enveloppe entière et `$changes` n'en surcharge que la partie utile.
     *
     * @param array<string, mixed> $changes
     */
    private function putPeriod(User $user, string $entryId, array $changes): void
    {
        $this->client->request('PUT', '/api/calendar_entries/' . $entryId, [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($changes + [
            // Union `+` : la GAUCHE gagne — $changes surcharge donc bien ces défauts.
            'kind' => 'period',
            'startDate' => '2026-10-19',
            'endDate' => '2026-11-02',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    private function planOf(string $clubId, string $calendarEntryId): ?SchedulePlan
    {
        $this->scopeGucToClub($clubId);
        // clear(): la requête HTTP a son propre EntityManager — sans ça on relirait
        // un UnitOfWork qui ignore les écritures faites côté serveur.
        $this->em->clear();

        return $this->em->getRepository(SchedulePlan::class)->findOneBy(['calendarEntryId' => $calendarEntryId]);
    }

    private function scheduleCount(string $clubId): int
    {
        $this->scopeGucToClub($clubId);

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM schedule WHERE club_id = :cid',
            ['cid' => $clubId],
        );
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function createClubWithSeason(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club plan-au-geste');
        $club->setSlug('club-plan-geste-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('PLG' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('plangeste' . $uid . '@test.com');
        $user->setFirstName('Plan');
        $user->setLastName('Geste');
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
