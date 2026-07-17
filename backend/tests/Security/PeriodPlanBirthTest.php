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
use App\Entity\VenueTrainingSlot;
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
     * l'idempotence de provisionPeriodPlan. Un PUT (qui re-provisionne) ne doit pas
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
     * NR — une période qui porte un plan a une IDENTITÉ GELÉE : rétrograder est refusé
     * (422), jamais silencieusement destructeur.
     *
     * Le geste n'existe pas dans l'UI (le front n'expose que POST et DELETE sur les
     * périodes) ; cette garde protège le chemin API direct. Elle rend inatteignables les
     * deux défauts des rounds 1-2 : le plan détruit sous ses versions, et sa fenêtre
     * périmée. Corriger un type ou des dates = supprimer la période et la recréer, ce que
     * l'UI impose déjà.
     */
    public function testDemotingAPeriodThatHasAPlanIsRefused(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances');
        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan);

        $this->putPeriodExpecting(422, $user, $entryId, ['periodType' => 'cutoff', 'title' => 'Vacances']);

        $survivor = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $survivor, 'le plan survit au refus');
        self::assertSame($plan->getId(), $survivor->getId());
    }

    /**
     * NR — corollaire : la fenêtre d'une période qui porte un plan est gelée elle aussi.
     * C'est ce qui rend impossible le « plan aux dates périmées » du round 1, sans aucune
     * machinerie de synchronisation.
     */
    public function testEditingTheDatesOfAPeriodThatHasAPlanIsRefused(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances');

        $this->putPeriodExpecting(422, $user, $entryId, [
            'periodType' => 'holiday',
            'title' => 'Vacances',
            'startDate' => '2027-02-15',
            'endDate' => '2027-02-22',
        ]);

        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan);
        self::assertSame('2026-10-19', $plan->getStartDate()->format('Y-m-d'), 'la fenêtre du plan ne peut pas diverger : elle est gelée avec celle de sa période');
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

    /**
     * NR — LE test qui manquait au round 1 : rétrograder une période qui porte une
     * VERSION doit être REFUSÉ (422), jamais détruire son plan.
     *
     * La version est volontairement PENDING et `overlayScheduleId` volontairement null :
     * c'est l'état exact que produit la suppression de la version active quand la seule
     * sœur restante n'est pas COMPLETED (OverlayManager ne promeut que du COMPLETED).
     * C'est par cette brèche que la garde d'identité, keyée sur `overlayScheduleId`,
     * laissait passer la rétrogradation — et que le plan généré partait en silence.
     */
    public function testDemotingAPeriodThatCarriesAVersionIsRefused(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances déjà générées');
        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan);
        $planId = $plan->getId();

        // Une version PENDING pend au plan, sans pointeur actif sur l'entrée.
        $this->scopeGucToClub($club->getId());
        $version = new Schedule;
        $version->setClubId($club->getId());
        $version->setSeasonId($season->getId());
        $version->setCalendarEntryId($entryId);
        $version->setSchedulePlanId($planId);
        $version->setName('V1');
        $version->setStatus(ScheduleStatus::PENDING);
        $this->em->persist($version);
        $this->em->flush();

        $this->client->request('PUT', '/api/calendar_entries/' . $entryId, [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'periodType' => 'cutoff',
            'title' => 'Vacances déjà générées',
            'startDate' => '2026-10-19',
            'endDate' => '2026-11-02',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        $survivor = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $survivor, 'le plan qui porte une version ne doit pas être détruit');
        self::assertSame($planId, $survivor->getId());
    }

    /**
     * NR lot C2 — inv. 5 : LES RÉGLAGES PENDENT AU PLAN. Deux plans d'une même saison ne
     * voient jamais les réglages l'un de l'autre.
     *
     * Aujourd'hui la relation période↔plan est 1:1 (uniq_schedule_plan_calendar_entry), donc
     * ce test passerait aussi avec l'ancienne ancre — ce n'est PAS une redondance : il fixe
     * le contrat que le découpage hebdomadaire (types-de-planning E1) exigera, quand deux
     * plans partageront le MÊME déclencheur et que `calendarEntryId` ne saura plus les
     * distinguer. Il garde aussi le cloisonnement contre une régression du filtre.
     */
    public function testPeriodSettingsHangOffThePlanNotTheCalendarEntry(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $planA = $this->planOf($club->getId(), $this->postPeriod($user, 'holiday', 'Toussaint'));
        $planB = $this->planOf($club->getId(), $this->postPeriod($user, 'closure', 'Gymnase en travaux'));
        self::assertInstanceOf(SchedulePlan::class, $planA);
        self::assertInstanceOf(SchedulePlan::class, $planB);

        // teamId opaque : le sujet est le cloisonnement par plan, pas l'équipe (l'API ne
        // valide pas son existence — même parti pris que TeamPeriodOverrideApiTest).
        $teamId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $this->client->request('POST', '/api/team_period_overrides', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'schedulePlanId' => $planA->getId(),
            'teamId' => $teamId,
            'isActive' => false,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        // Le réglage revient par SON plan…
        self::assertCount(1, $this->overridesOf($user, $planA->getId()), 'le réglage se relit par le plan qui le porte');
        // …et reste invisible à l'autre.
        self::assertCount(0, $this->overridesOf($user, $planB->getId()), 'un plan ne voit jamais les réglages d’un autre');
    }

    /**
     * NR lot C3 — les CALQUES aussi pendent au plan, et leur nullité garde son sens.
     *
     * Ancre nullable : NULL = la structure PARTAGÉE (inv. 6 — créneau saisonnier,
     * réservation de base), non-NULL = propre à ce plan. C'est plus dangereux que les
     * jumeaux de C2 : une ancre mélangée ne casse rien, elle fait passer une ligne de
     * PÉRIODE pour une ligne de BASE — le socle hériterait d'un gymnase prêté pour une
     * semaine de vacances, et le planning serait plausible mais faux.
     */
    public function testPeriodLayersHangOffThePlanAndNullStillMeansShared(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $planId = $this->planOf($club->getId(), $this->postPeriod($user, 'holiday', 'Vacances'))?->getId();
        self::assertIsString($planId);

        $this->scopeGucToClub($club->getId());
        $venueId = '99999999-9999-4999-8999-999999999999';
        // Un créneau SAISONNIER (ancre nulle) et un créneau PRÊTÉ à ce plan.
        foreach ([null, $planId] as $anchor) {
            $slot = new VenueTrainingSlot;
            $slot->setClubId($club->getId());
            $slot->setSeasonId($season->getId());
            $slot->setVenueId($venueId);
            $slot->setDayOfWeek(null === $anchor ? 1 : 2);
            $slot->setStartTime(new DateTimeImmutable('18:00'));
            $slot->setDurationMinutes(90);
            $slot->setCapacity(1);
            $slot->setSchedulePlanId($anchor);
            $this->em->persist($slot);
        }
        $this->em->flush();
        $this->em->clear();
        $this->scopeGucToClub($club->getId());

        $repo = $this->em->getRepository(VenueTrainingSlot::class);
        self::assertCount(1, $repo->findBy(['schedulePlanId' => null]), 'le créneau saisonnier reste SANS ancre : c’est la structure partagée (inv. 6), pas un réglage de période');
        self::assertCount(1, $repo->findBy(['schedulePlanId' => $planId]), 'le créneau prêté pend au plan');
    }

    /**
     * NR lot C3 — les contraintes DATÉES, elles, NE bougent PAS : elles restent sur la
     * CalendarEntry, et le RADAR doit pouvoir les lire AVANT tout plan.
     *
     * Décision fondateur (2026-07-17), qui a levé une contradiction de l'ADR : une
     * contrainte datée décrit le FAIT (« Barros fermé »), pas la réponse. Le radar la lit
     * PAR L'ENTRÉE pour annoncer « cette fermeture gêne 3 séances » — c'est ce qui
     * DÉCLENCHE le geste « ajuster ». L'ancrer au plan la rendrait illisible tant qu'aucun
     * plan n'existe… or le plan naît de ce geste : le radar ne pourrait plus jamais le
     * provoquer.
     *
     * On teste le COMPORTEMENT (la contrainte se relit par son entrée), pas la forme de la
     * classe : un method_exists ne dirait rien de ce qui compte.
     */
    public function testDatedConstraintsStayReadableByTheirCalendarEntry(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'closure', 'Barros fermé');

        $this->scopeGucToClub($club->getId());
        $dated = new Constraint;
        $dated->setClubId($club->getId());
        $dated->setSeasonId($season->getId());
        $dated->setName('Barros fermé');
        $dated->setScope(ConstraintScope::FACILITY);
        $dated->setFamily(ConstraintFamily::FACILITY);
        $dated->setRuleType(ConstraintRuleType::HARD);
        $dated->setConfig([]);
        $dated->setCalendarEntryId($entryId); // le FAIT, pas la réponse
        $this->em->persist($dated);
        $this->em->flush();
        $this->em->clear();
        $this->scopeGucToClub($club->getId());

        // Le radar part du déclencheur, et il doit trouver — c'est ce qui déclenche le geste.
        self::assertCount(
            1,
            $this->em->getRepository(Constraint::class)->findBy(['calendarEntryId' => $entryId]),
            'la contrainte datée se relit par SON entrée : sans ça le radar ne peut plus annoncer l’impact d’une fermeture',
        );
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array<int, mixed> */
    private function overridesOf(User $user, string $schedulePlanId): array
    {
        $this->client->request('GET', '/api/team_period_overrides?schedulePlanId=' . $schedulePlanId, [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $items = $payload['member'] ?? $payload['hydra:member'] ?? $payload;
        self::assertIsArray($items);

        return array_values($items);
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

    private function putPeriod(User $user, string $entryId, array $changes): void
    {
        $this->putPeriodExpecting(null, $user, $entryId, $changes);
    }

    /**
     * PUT = remplacement complet : kind/startDate/endDate sont NotBlank, on renvoie donc
     * l'enveloppe entière et `$changes` n'en surcharge que la partie utile (union `+` :
     * la GAUCHE gagne).
     *
     * @param array<string, mixed> $changes
     */
    private function putPeriodExpecting(?int $status, User $user, string $entryId, array $changes): void
    {
        $this->client->request('PUT', '/api/calendar_entries/' . $entryId, [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($changes + [
            'kind' => 'period',
            'startDate' => '2026-10-19',
            'endDate' => '2026-11-02',
        ], \JSON_THROW_ON_ERROR));

        if (null === $status) {
            self::assertResponseIsSuccessful();

            return;
        }
        self::assertResponseStatusCodeSame($status);
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
