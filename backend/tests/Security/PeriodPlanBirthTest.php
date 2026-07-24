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
 * NR — ADR-0002 (amendé 2026-07-24), axe *planning lifecycle* : LE PLAN NAÎT DU
 * GESTE D'ADAPTER.
 *
 * Décision fondateur (2026-07-24, durcit celle du 2026-07-17) : un plan naît
 * UNIQUEMENT d'un geste EXPLICITE d'adaptation — jamais de la simple existence
 * d'une période. Les gestes : POST /api/schedule_plans {calendarEntryId} (« Adapter »
 * un bloc ou une fermeture), et cocher une semaine au picker (l'entrée-SEMAINE naît
 * avec son plan — couvert par WeekChildEntryTest). Matérialiser une vacance ou
 * signaler une indisponibilité ne crée RIEN : l'entrée est un ancrage, le radar lit
 * l'impact par les contraintes datées, sans plan.
 *
 * Ce que ce test verrouille :
 *  1. créer une période closure/holiday ⇒ AUCUN plan (matérialiser ≠ adapter) ;
 *  2. le geste (POST /schedule_plans) ⇒ le plan existe AVANT toute génération,
 *     type suivant la période ; rejoué ⇒ toujours UN SEUL plan (idempotence) ;
 *  3. inv. 9 — cutoff/mutualisation : jamais de plan, le geste y répond 422 ;
 *  4. un PUT ne crée JAMAIS de plan (promotion comprise) — anti-résurrection ;
 *  5. l'identité d'une période à plan OU à semaines-enfants est GELÉE (422) ;
 *  6. une mère découpée ne reporte jamais de plan-bloc (422) tant que ses semaines
 *     existent — les supprimer rouvre le geste (symétrie fondateur) ;
 *  7. SEC-07 : le geste est réservé au management (403 sinon).
 */
#[Group('phase1')]
#[Group('integration')]
final class PeriodPlanBirthTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testCreatingAHolidayPeriodDoesNotBirthAPlan(): void
    {
        [$user, $club] = $this->createClubWithSeason();

        $entryId = $this->postPeriod($user, 'holiday', 'Vacances de Toussaint');

        // Amendement 2026-07-24 : matérialiser la vacance = un ANCRAGE, pas une
        // adaptation. Aucun plan tant que le gestionnaire n'a pas cliqué Adapter.
        self::assertNull($this->planOf($club->getId(), $entryId), 'Matérialiser une période ne crée pas de plan.');
    }

    public function testAdaptGestureBirthsTheHolidayPlanBeforeAnyGeneration(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances de Toussaint');

        $this->adaptPeriod($user, $entryId);

        // Le cœur : le plan est là alors qu'AUCUN schedule n'existe.
        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan, 'Le geste « Adapter » doit créer le plan de la période.');
        self::assertSame(SchedulePlanType::HOLIDAY, $plan->getType());
        self::assertFalse($plan->isTeamSelectionInitialized(), 'Un plan neuf n’est pas encore configuré (garde de seed).');
        self::assertSame(0, $this->scheduleCount($club->getId()), 'Aucune génération ne doit être nécessaire.');
    }

    public function testAdaptGestureBirthsAClosurePlan(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'closure', 'Gymnase en travaux');
        self::assertNull($this->planOf($club->getId(), $entryId), 'Signaler une indisponibilité ne crée pas de plan.');

        $this->adaptPeriod($user, $entryId);

        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan);
        self::assertSame(SchedulePlanType::CLOSURE, $plan->getType());
    }

    /**
     * Invariant 9 — « Périodes sans plan » : cutoff/mutualisation restent des rappels
     * calendrier. Le geste Adapter y répond 422 : leur créer un plan donnerait un
     * espace de travail fantôme, jamais générable, dans le sélecteur.
     */
    public function testNonGeneratingPeriodsCarryNoPlanAndRefuseTheGesture(): void
    {
        [$user, $club] = $this->createClubWithSeason();

        foreach (['cutoff', 'mutualisation'] as $periodType) {
            $entryId = $this->postPeriod($user, $periodType, 'Rappel ' . $periodType);
            self::assertNull(
                $this->planOf($club->getId(), $entryId),
                \sprintf('Une période « %s » ne porte pas de plan (inv. 9).', $periodType),
            );
            $this->adaptPeriodExpecting(422, $user, $entryId);
        }
    }

    /**
     * Un PUT ne crée JAMAIS de plan (amendement 2026-07-24) : « promouvoir » un rappel
     * en période génératrice n'est pas un geste d'adaptation — et ce scénario n'existe
     * pas dans l'UI (ruling fondateur : « une coupure ne devient pas des vacances »).
     * Le plan naîtra du clic Adapter, la porte POST existe désormais pour ça.
     */
    public function testPromotingANonGeneratingPeriodDoesNotBirthAPlan(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'cutoff', 'Rappel à promouvoir');
        self::assertNull($this->planOf($club->getId(), $entryId));

        $this->putPeriod($user, $entryId, ['periodType' => 'holiday', 'title' => 'Rappel à promouvoir']);

        self::assertNull($this->planOf($club->getId(), $entryId), 'Un PUT ne mint jamais de plan (anti-résurrection).');
    }

    /**
     * Un plan par période — garanti par uniq_schedule_plan_calendar_entry ET par
     * l'idempotence de provisionPeriodPlan : le geste rejoué rend le MÊME plan,
     * et un PUT ultérieur n'en fait pas naître un second.
     */
    public function testTheGestureReplayedDoesNotDuplicateThePlan(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances');

        $firstPlanId = $this->adaptPeriod($user, $entryId);
        $secondPlanId = $this->adaptPeriod($user, $entryId, 201);
        self::assertSame($firstPlanId, $secondPlanId, 'Le geste rejoué rend le même plan.');

        $this->putPeriod($user, $entryId, ['periodType' => 'holiday', 'title' => 'Vacances renommées']);

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $plans = $this->em->getRepository(SchedulePlan::class)->findBy(['calendarEntryId' => $entryId]);
        self::assertCount(1, $plans, 'Ni le geste rejoué ni un PUT ne dupliquent le plan.');
    }

    /**
     * NR — une mère DÉCOUPÉE ne reporte jamais de plan-bloc : 422 tant que des
     * semaines-enfants existent. État réversible, pas verrou définitif (symétrie
     * fondateur : on ne bascule jamais semaines↔bloc automatiquement — supprimer
     * toutes les semaines rouvre le geste bloc).
     */
    public function testAdaptGestureOnASplitMotherIsRefusedUntilChildrenAreDeleted(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'holiday', 'Vacances découpées');
        $childId = $this->postWeekChild($user, $motherId, '2026-10-19', '2026-10-25');

        $this->adaptPeriodExpecting(422, $user, $motherId);
        self::assertNull($this->planOf($club->getId(), $motherId), 'Pas de plan-bloc sur une mère découpée.');

        // Symétrie : la semaine supprimée (cascade : son plan part avec), le geste
        // bloc redevient légitime.
        $this->client->request('DELETE', '/api/calendar_entries/' . $childId, [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(204);

        $this->adaptPeriod($user, $motherId);
        $plan = $this->planOf($club->getId(), $motherId);
        self::assertInstanceOf(SchedulePlan::class, $plan, 'Semaines supprimées → le geste bloc rouvre.');
        self::assertSame(SchedulePlanType::HOLIDAY, $plan->getType());
    }

    /**
     * NR anti-résurrection — un PUT sur une mère découpée (sans plan) : le titre reste
     * éditable, mais l'identité est gelée par ses SEMAINES et aucun plan ne re-naît.
     */
    public function testPutOnASplitMotherDoesNotResurrectAPlanAndFreezesIdentity(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'holiday', 'Vacances découpées');
        $this->postWeekChild($user, $motherId, '2026-10-19', '2026-10-25');
        self::assertNull($this->planOf($club->getId(), $motherId));

        // Titre : libre — et toujours aucun plan après.
        $this->putPeriod($user, $motherId, ['periodType' => 'holiday', 'title' => 'Titre changé']);
        self::assertNull($this->planOf($club->getId(), $motherId), 'Un PUT ne re-mint pas le plan-bloc d’une mère découpée.');

        // Dates : gelées par les enfants (la couverture bougerait sous les semaines).
        $this->putPeriodExpecting(422, $user, $motherId, [
            'periodType' => 'holiday',
            'title' => 'Titre changé',
            'startDate' => '2027-02-15',
            'endDate' => '2027-02-22',
        ]);
    }

    /** SEC-07 — le geste Adapter est une écriture cockpit : membre non-management → 403. */
    public function testAdaptGestureRequiresManagementRole(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances');

        $coach = $this->addMember($club, 'coach');
        $this->client->request('POST', '/api/schedule_plans', [], [], $this->authHeaders($coach) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->planOf($club->getId(), $entryId));
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
        $this->adaptPeriod($user, $entryId);
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
        $this->adaptPeriod($user, $entryId);

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
        $this->adaptPeriod($user, $entryId);

        // Le nom de NAISSANCE du plan est la réponse générée (E6, « Planning de vacances … »),
        // distincte du titre de la période — on le capture, puis on prouve qu'il ne bouge pas.
        $bornPlan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $bornPlan);
        $birthName = $bornPlan->getName();

        $this->putPeriod($user, $entryId, ['periodType' => 'holiday', 'title' => 'Titre changé']);

        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan);
        self::assertSame($birthName, $plan->getName(), 'le nom du plan ne suit pas le titre de la période (inv. 12)');
        self::assertStringNotContainsString('Titre changé', $plan->getName());
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
        $this->adaptPeriod($user, $entryId);
        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan);
        $planId = $plan->getId();

        // Une version PENDING pend au plan, sans pointeur actif sur l'entrée.
        $this->scopeGucToClub($club->getId());
        $version = new Schedule;
        $version->setClubId($club->getId());
        $version->setSeasonId($season->getId());
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
        $entryA = $this->postPeriod($user, 'holiday', 'Toussaint');
        $entryB = $this->postPeriod($user, 'closure', 'Gymnase en travaux');
        $this->adaptPeriod($user, $entryA);
        $this->adaptPeriod($user, $entryB);
        $planA = $this->planOf($club->getId(), $entryA);
        $planB = $this->planOf($club->getId(), $entryB);
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
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances');
        $planId = $this->adaptPeriod($user, $entryId);

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
     * RENFORCÉ par l'amendement 2026-07-24 : une closure vit désormais SANS plan tant
     * que personne ne clique Adapter — le radar n'a QUE la contrainte datée pour
     * annoncer « cette fermeture gêne 3 séances », et c'est ce qui déclenche le geste.
     *
     * On teste le COMPORTEMENT (la contrainte se relit par son entrée), pas la forme de la
     * classe : un method_exists ne dirait rien de ce qui compte.
     */
    public function testDatedConstraintsStayReadableByTheirCalendarEntry(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'closure', 'Barros fermé');
        // Volontairement AUCUN adaptPeriod : la lecture doit marcher sans plan.

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

    /** Le geste « Adapter » : POST /api/schedule_plans — rend l'id du plan (201). */
    private function adaptPeriod(User $user, string $entryId, int $expected = 201): string
    {
        $this->client->request('POST', '/api/schedule_plans', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($expected);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    private function adaptPeriodExpecting(int $status, User $user, string $entryId): void
    {
        $this->client->request('POST', '/api/schedule_plans', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($status);
    }

    /** POST d'une entrée-SEMAINE (P2-5 E1) — elle naît AVEC son plan (le geste = cocher). */
    private function postWeekChild(User $user, string $motherId, string $start, string $end): string
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'title' => 'Semaine du ' . $start,
            'startDate' => $start,
            'endDate' => $end,
            'periodType' => 'holiday',
            'parentEntryId' => $motherId,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    private function addMember(Club $club, string $role): User
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $member = new User;
        $member->setEmail('member' . $uid . '@test.com');
        $member->setFirstName('Non');
        $member->setLastName('Manager');
        $member->setPasswordHash($hasher->hashPassword($member, 'pass'));
        $this->em->persist($member);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($member->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $member;
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

    /** @param array<string, mixed> $changes */
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
