<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\Reservation;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamPeriodOverride;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
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
 * P2-9 PR A (axe constraint semantics §7.1) — le volet CAPACITÉ du récap, dans les
 * deux sens (décision fondateur 2026-07-31), CÂBLÉ : le message ressort de l'API.
 * La spec l'exige ainsi — « le calcul en isolation ne suffit pas : un test qui
 * alimente lui-même la valeur qu'il prétend vérifier ne garde rien ».
 *
 * Les nombres viennent du PAYLOAD (`buildForClubSeason` / `buildForPeriodPlan`),
 * jamais d'un recalcul à la main — trois tentatives sont mortes là-dessus. Le cas
 * période le PROUVE : la demande y est modifiée par une surcharge de séances
 * (`TeamPeriodOverride`) et l'offre par la grille POSSÉDÉE par le plan — deux
 * réglages qu'un recalcul manuel a historiquement ratés.
 */
#[Group('phase1')]
#[Group('security')]
final class RecapCapacityWarningTest extends WebTestCase
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    private SportCategory $category;

    /**
     * Saison, sous-capacité : 2 équipes (3+2 = 5 séances), un créneau à capacité 2 et un
     * à 1 (offre 3) → « au moins 2 » — la formulation du fondateur, jamais « exactement ».
     */
    public function testSeasonUnderCapacityIsAnnouncedAsAtLeast(): void
    {
        [$user, $club, $season] = $this->seedClubSeason('CAPU');
        $this->team($club, $season, 3);
        $this->team($club, $season, 2);
        $venue = $this->venue($club, $season);
        $this->slot($club, $season, $venue, '18:00', 2);
        $this->slot($club, $season, $venue, '19:30', 1);

        $body = $this->validate($user, $club, null);

        self::assertTrue($body['valid'], 'la capacité AVERTIT, elle ne bloque jamais');
        $all = implode(' | ', array_map(strval(...), $body['warnings']));
        self::assertStringContainsString('demandent 5 séances', $all);
        self::assertStringContainsString('n\'en offrent que 3', $all);
        self::assertStringContainsString('au moins 2 séances ne pourront pas être placées', $all);
    }

    /**
     * Offre = demande : AUCUN warning de capacité (`>=` et non `>` — la règle de la spec).
     * C'est la borne que la falsification fait tomber si la comparaison dévie.
     */
    public function testExactCapacityStaysSilent(): void
    {
        [$user, $club, $season] = $this->seedClubSeason('CAPE');
        $this->team($club, $season, 3);
        $venue = $this->venue($club, $season);
        $this->slot($club, $season, $venue, '18:00', 2);
        $this->slot($club, $season, $venue, '19:30', 1);

        $body = $this->validate($user, $club, null);

        self::assertSame([], $body['warnings'], 'offre égale à demande : rien à dire, dans aucun des deux sens');
    }

    /**
     * Surplus (décision fondateur 2026-07-31 : signalé dès le premier créneau en trop,
     * en ton informatif) : 2 séances demandées, offre 4 → « 2 resteront libres ».
     */
    public function testSurplusIsAnnouncedFromTheFirstSpareSlot(): void
    {
        [$user, $club, $season] = $this->seedClubSeason('CAPS');
        $this->team($club, $season, 2);
        $venue = $this->venue($club, $season);
        $this->slot($club, $season, $venue, '18:00', 2);
        $this->slot($club, $season, $venue, '19:30', 2);

        $body = $this->validate($user, $club, null);

        self::assertTrue($body['valid']);
        $all = implode(' | ', array_map(strval(...), $body['warnings']));
        self::assertStringContainsString('offrent jusqu\'à 4 places de créneau pour 2 séances demandées', $all);
        self::assertStringContainsString('2 places pourraient rester libres', $all, 'le surplus se déduit d\'un MAJORANT : « pourraient », jamais un fait (revue #341)');
        self::assertStringContainsString('augmenter les séances', $all, 'le surplus est une INFORMATION actionnable, pas une alarme');
    }

    /**
     * SINGULIER (revue #341) : « 1 séance », « 1 place » — les messages interpolaient des
     * entiers dans une formulation au pluriel seul (« 1 séances »). Cas COURANT sur un
     * petit club ou une période à une seule équipe, pas un cas limite.
     */
    public function testSingularWordingAgrees(): void
    {
        [$user, $club, $season] = $this->seedClubSeason('CAP1');
        $this->team($club, $season, 2);
        $venue = $this->venue($club, $season);
        $this->slot($club, $season, $venue, '18:00', 1);

        $body = $this->validate($user, $club, null);

        $all = implode(' | ', array_map(strval(...), $body['warnings']));
        self::assertStringContainsString('au moins 1 séance ne pourra pas être placée', $all);
        self::assertStringNotContainsString('1 séances', $all);
    }

    /**
     * Les trois lectures du moteur que la somme BRUTE ignorait (revue #341) — chacune
     * gonflait l'offre annoncée, donc pouvait taire une sous-capacité RÉELLE ou, pire,
     * conseiller d'ajouter des séances à un club qui en manque :
     *
     * 1. deux créneaux au même `(gymnase, jour, heure)` s'ÉCRASENT (dict côté moteur) ;
     * 2. un verrou HARD bloque le triplet pour TOUT LE MONDE : il vaut 1 place, pas sa capacité ;
     * 3. `FACILITY_CAPACITY` rabote à `min(capacity, maxTeams)`.
     */
    public function testOfferIsReadTheWayTheEngineReadsIt(): void
    {
        [$user, $club, $season] = $this->seedClubSeason('CAPM');
        $team = $this->team($club, $season, 8);
        // ⚠ Trois gymnases SÉPARÉS : `FACILITY_CAPACITY` est clé PAR GYMNASE, donc sur un
        // gymnase unique son rabot MASQUE la réduction du verrou — le test restait vert en
        // retirant celle-ci (constaté en falsifiant). Chaque lecture doit s'isoler.
        $dup = $this->venue($club, $season, 'Doublon');
        $locked = $this->venue($club, $season, 'Verrouillé');
        $capped = $this->venue($club, $season, 'Rabotté');

        // 1) Doublon exact (gymnase, jour, heure) : le moteur ÉCRASE — 2 places, pas 4.
        $this->slot($club, $season, $dup, '18:00', 2);
        $this->slot($club, $season, $dup, '18:00', 2);
        // 2) Verrou HARD : le triplet est bloqué pour TOUS — 1 place, pas 3.
        $this->slot($club, $season, $locked, '20:00', 3);
        $this->reservation($club, $season, $locked, $team, '20:00');
        // 3) FACILITY_CAPACITY maxTeams=1 : min(3, 1) — 1 place, pas 3.
        $this->slot($club, $season, $capped, '21:30', 3);
        $this->facilityCapacity($club, $season, $capped, 1);

        $body = $this->validate($user, $club, null);

        // Lecture BRUTE : 2+2+3+3 = 10 → « surplus » pour 8 séances : un mensonge qui
        // conseillerait d'AJOUTER des séances. Lecture MOTEUR : 2 + 1 + 1 = 4 → sous-capacité.
        $all = implode(' | ', array_map(strval(...), $body['warnings']));
        self::assertStringContainsString('n\'en offrent que 4', $all, 'doublon écrasé, verrou à 1 place, capacité rabotée — la somme brute aurait dit 10 et annoncé un surplus');
        self::assertStringContainsString('au moins 4 séances', $all);
    }

    /**
     * PÉRIODE : les nombres viennent du payload de LA PÉRIODE — demande modifiée par la
     * surcharge de séances (3 → 2), offre lue dans la grille POSSÉDÉE par le plan (la
     * COPIE de la saison prise à sa naissance : 2 places). Demande 2 = offre 2 → silence,
     * là où les nombres de SAISON (3 demandées, 2 offertes) auraient déclenché la
     * sous-capacité. Un recalcul manuel qui lirait la saison — la faute des trois
     * tentatives mortes — fait donc rougir ce test.
     */
    public function testPeriodNumbersComeFromThePeriodPayloadNotTheSeason(): void
    {
        [$user, $club, $season] = $this->seedClubSeason('CAPP');
        $team = $this->team($club, $season, 3);
        $venue = $this->venue($club, $season);
        $this->slot($club, $season, $venue, '18:00', 1);
        $this->slot($club, $season, $venue, '19:30', 1);

        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Reprise capacité');
        $entry->setStartDate(new DateTimeImmutable('2025-10-20'));
        $entry->setEndDate(new DateTimeImmutable('2025-10-26'));
        $entry->setIsDisruptive(false);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();
        $planId = $this->planIdOf($entry);

        // La période : l'équipe passe à 2 séances ; sa grille possédée est la COPIE de la
        // saison (2 créneaux à capacité 1), prise par provisionPeriodPlan à la naissance.
        $override = new TeamPeriodOverride;
        $override->setClubId($club->getId());
        $override->setSeasonId($season->getId());
        $override->setSchedulePlanId($planId);
        $override->setTeamId($team->getId());
        $override->setIsActive(true);
        $override->setSessionsPerWeek(2);
        $this->em->persist($override);
        $this->em->flush();

        $body = $this->validate($user, $club, $entry->getId());

        self::assertSame([], $body['warnings'], 'demande 2 (surcharge de période) = offre 2 (grille copiée du plan) : silence — les nombres de saison (3 vs 2) auraient déclenché la sous-capacité');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get(UserPasswordHasherInterface::class);
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seedClubSeason(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club capacité ' . $tag);
        $club->setSlug('cap-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('cap-' . $tag . '-' . $uid . '@test.com');
        $user->setFirstName('Ca');
        $user->setLastName('Pa');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->em->persist($season);

        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('cap-' . $uid);
        $sport->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U11');
        $category->setIsCustom(false);
        $category->setSortOrder(0);
        $this->em->persist($category);

        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = new PriorityTier;
            $tier->setId(1);
            $tier->setLabel('S');
            $tier->setName('Senior');
            $tier->setColor('#FF0000');
            $tier->setOrToolsWeight(100);
            $tier->setDefaultMinSessions(2);
            $this->em->persist($tier);
        }
        $this->em->flush();
        $this->category = $category;

        return [$user, $club, $season];
    }

    private function team(Club $club, Season $season, int $sessionsPerWeek): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($this->category->getId());
        $team->setPriorityTierId(1);
        $team->setName('Équipe ' . uniqid());
        $team->setSessionsPerWeek($sessionsPerWeek);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function venue(Club $club, Season $season, string $name = 'Gymnase capacité'): Venue
    {
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        // canSplit : les capacités > 1 des créneaux comptent (sinon bridées à 1 — c'est
        // exactement le réglage que la tentative n° 2 avait raté en recalculant à la main).
        $venue->setCanSplit(true);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function slot(Club $club, Season $season, Venue $venue, string $start, int $capacity): void
    {
        $slot = new VenueTrainingSlot;
        $slot->setClubId($club->getId());
        $slot->setSeasonId($season->getId());
        $slot->setVenueId($venue->getId());
        $slot->setDayOfWeek(1);
        $slot->setStartTime(new DateTimeImmutable($start));
        $slot->setDurationMinutes(90);
        $slot->setCapacity($capacity);
        // Toujours SAISONNIER : la grille d'une période est la COPIE que `planIdOf` prend à
        // la naissance du plan — en fabriquer une ici produirait un créneau que ce geste n'a
        // pas créé, donc un état impossible en production (revue #341).
        $slot->setSchedulePlanId(null);
        $this->em->persist($slot);
        $this->em->flush();
    }

    private function reservation(Club $club, Season $season, Venue $venue, Team $team, string $start): void
    {
        $reservation = new Reservation;
        $reservation->setClubId($club->getId());
        $reservation->setSeasonId($season->getId());
        $reservation->setVenueId($venue->getId());
        $reservation->setTeamId($team->getId());
        $reservation->setDayOfWeek(1);
        $reservation->setStartTime(new DateTimeImmutable($start));
        $reservation->setDurationMinutes(90);
        $this->em->persist($reservation);
        $this->em->flush();
    }

    private function facilityCapacity(Club $club, Season $season, Venue $venue, int $maxTeams): void
    {
        $constraint = new Constraint;
        $constraint->setClubId($club->getId());
        $constraint->setSeasonId($season->getId());
        $constraint->setScope(ConstraintScope::FACILITY);
        $constraint->setScopeTargetId($venue->getId());
        $constraint->setFamily(ConstraintFamily::FACILITY_CAPACITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setName('Capacité ' . $venue->getName());
        $constraint->setConfig(['venueId' => $venue->getId(), 'maxTeams' => $maxTeams]);
        $constraint->setIsActive(true);
        $constraint->setSortOrder(0);
        $this->em->persist($constraint);
        $this->em->flush();
    }

    /**
     * @return array{valid: bool, warnings: list<string>}
     */
    private function validate(User $user, Club $club, ?string $calendarEntryId): array
    {
        $this->client->request('POST', '/api/constraints/validate', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'HTTP_X-Club-Id' => $club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(null === $calendarEntryId ? [] : ['calendarEntryId' => $calendarEntryId], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        /* @var array{valid: bool, warnings: list<string>} */
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
