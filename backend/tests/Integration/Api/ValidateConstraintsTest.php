<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\TeamCoachRole;
use App\Enum\VenuePeriodMode;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * BW3 — the pre-solve gate surfaces gross constraint errors before generation.
 */
#[Group('integration')]
#[Group('phase1')]
final class ValidateConstraintsTest extends WebTestCase
{
    use ProvisionsPeriodPlanTrait;

    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private Club $club;

    private User $user;

    private Season $season;

    public function testCleanConstraintsAreValid(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], ['HTTP_X-Club-Id' => $this->club->getId()]);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['valid']);
    }

    public function testContradictoryHardTimeConstraintsAreRejected(): void
    {
        // Two CLUB HARD TIME rules: "not after 10:00" vs "not before 12:00" — impossible.
        $this->constraint(['maxStartTime' => '10:00']);
        $this->constraint(['minStartTime' => '12:00']);

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], ['HTTP_X-Club-Id' => $this->club->getId()]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($data['valid']);
        self::assertNotEmpty($data['conflicts']);
    }

    public function testVenueMinimumExceedingTeamSessionsIsRejectedBeforeGeneration(): void
    {
        // ALIGN-05 fail-fast: "au moins 2 séances à ce gymnase" for a 1-session/week
        // team is provably impossible — surface it as an ERROR before generating.
        $teamId = $this->team(sessionsPerWeek: 1);
        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setName('min venue');
        $constraint->setScope(ConstraintScope::TEAM);
        $constraint->setScopeTargetId($teamId);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['minAtVenueId' => 'venue-x', 'minAtVenueCount' => 2]);
        $constraint->setIsActive(true);
        $this->em->persist($constraint);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], ['HTTP_X-Club-Id' => $this->club->getId()]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($data['valid']);
        self::assertNotEmpty($data['errors'], 'the impossible venue minimum must surface as an error');
    }

    /**
     * PR A (2026-08-06) — saturation des « au moins » par gymnase. Deux équipes exigent
     * chacune 1 séance à Matéo (2 places demandées) ; le gymnase a 2 créneaux mais une
     * réservation en verrouille un (un triplet verrouillé n'a de variable pour PERSONNE,
     * `model.py:62-63`) : 1 place libre pour 2 demandées → INFEASIBLE certain, BLOQUEUR.
     * C'est le scénario BCCL du jour, découvert en échec de génération muet.
     */
    public function testVenueMinimumsSaturatedByReservationsBlock(): void
    {
        $venueId = $this->venue('Matéo');
        $this->trainingSlot($venueId, dayOfWeek: 1, start: '18:00');
        $this->trainingSlot($venueId, dayOfWeek: 2, start: '18:00');
        $teamA = $this->team(sessionsPerWeek: 1);
        $teamB = $this->team(sessionsPerWeek: 1);
        $this->minAtVenue($teamA, $venueId);
        $this->minAtVenue($teamB, $venueId);
        $this->reservation($teamA, $venueId, dayOfWeek: 1, start: '18:00');

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], ['HTTP_X-Club-Id' => $this->club->getId()]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($data['valid']);
        $blockers = implode(' | ', $data['blockers']);
        self::assertStringContainsString('Matéo', $blockers, 'le bloqueur doit nommer le gymnase saturé');
        self::assertStringContainsString('2 places', $blockers);
    }

    /** Témoin du cas ci-dessus : sans la réservation, 2 places libres pour 2 demandées — rien ne bloque. */
    public function testVenueMinimumsWithinFreeCapacityDoNotBlock(): void
    {
        $venueId = $this->venue('Matéo');
        $this->trainingSlot($venueId, dayOfWeek: 1, start: '18:00');
        $this->trainingSlot($venueId, dayOfWeek: 2, start: '18:00');
        $this->minAtVenue($this->team(sessionsPerWeek: 1), $venueId);
        $this->minAtVenue($this->team(sessionsPerWeek: 1), $venueId);

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], ['HTTP_X-Club-Id' => $this->club->getId()]);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['valid']);
        self::assertSame([], $data['blockers']);
    }

    /**
     * PR A (2026-08-06) — coach indisponible × créneau réservé en dur : AVERTISSEMENT
     * avant génération (le verrou est souverain, la séance se posera quand même — mais
     * on le dit AVANT, pas en INFO post-solve). N'invalide rien : `valid` reste vrai.
     */
    public function testReservationOnCoachUnavailableDayWarnsButStaysValid(): void
    {
        $venueId = $this->venue('Matéo');
        $teamId = $this->team(sessionsPerWeek: 1);
        $coachId = $this->mainCoach($teamId, 'Emerick');
        $this->reservation($teamId, $venueId, dayOfWeek: 2, start: '17:00');

        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setName('Emerick indisponible le mardi');
        $constraint->setScope(ConstraintScope::COACH);
        $constraint->setScopeTargetId($coachId);
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['coachId' => $coachId, 'unavailableDays' => [2]]);
        $constraint->setIsActive(true);
        $this->em->persist($constraint);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], ['HTTP_X-Club-Id' => $this->club->getId()]);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['valid'], 'un avertissement n\'invalide rien — le verrou est souverain');
        $warnings = implode(' | ', $data['warnings']);
        self::assertStringContainsString('Emerick', $warnings);
        self::assertStringContainsString('mardi', $warnings);
        self::assertStringContainsString('Matéo', $warnings);
    }

    public function testOverlayValidationIncludesInheritedPermanentConstraints(): void
    {
        $this->constraint(['maxStartTime' => '10:00']);
        $this->constraint(['minStartTime' => '12:00']);
        $entry = $this->period(CalendarEntryPeriodType::CLOSURE);

        $this->client->loginUser($this->user);
        $this->client->request(
            'POST',
            '/api/constraints/validate',
            [],
            [],
            ['HTTP_X-Club-Id' => $this->club->getId()],
            json_encode(['calendarEntryId' => $entry->getId()], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($data['valid']);
        self::assertNotEmpty($data['conflicts'], 'overlay validation must include inherited permanent constraints');
    }

    /**
     * #8 — le gymnase est DÉSACTIVÉ pour la période : le solveur ne recevra pas les
     * contraintes qui le nomment. Le récap ne doit donc pas les valider en silence — il
     * les retire ET les annonce. Ici par le SCOPE (FACILITY + scopeTargetId).
     */
    public function testPermanentConstraintsNamingADisabledVenueAreWarnedAndDropped(): void
    {
        $venueId = $this->venue('Barros');
        $entry = $this->period(CalendarEntryPeriodType::CLOSURE);
        $this->disableVenueForPeriod($this->planIdOf($entry), $venueId);

        // Deux HARD TIME sur CE gymnase, contradictoires entre elles : si le filtre
        // disparaissait, le conflit ressortirait en 422 — l'assertion n'est pas vacante.
        $this->venueScopedConstraint('SM1 impose Barros', $venueId, ['maxStartTime' => '10:00']);
        $this->venueScopedConstraint('SM2 impose Barros', $venueId, ['minStartTime' => '12:00']);

        $data = $this->validateForPeriod($entry);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($data['valid'], 'un avertissement n’invalide rien');
        self::assertSame([], $data['conflicts'], 'les contraintes du gymnase désactivé sont retirées du jeu validé');
        self::assertCount(2, $data['warnings']);
        self::assertStringContainsString(
            '« SM1 impose Barros » vise le gymnase Barros',
            implode(' | ', $data['warnings']),
            'l’avertissement doit nommer LA CONTRAINTE et LE GYMNASE',
        );
    }

    /**
     * #8 — le même filtre s’applique aux contraintes DATÉES de la période. Ne filtrer que
     * les permanentes recréerait la désynchronisation gate/payload (buildForOverlay filtre
     * les deux). Ici le gymnase est nommé par la CONFIG (`minAtVenueId`).
     */
    public function testDatedConstraintNamingADisabledVenueIsWarnedAndDropped(): void
    {
        $venueId = $this->venue('Barros');
        $entry = $this->period(CalendarEntryPeriodType::CLOSURE);
        $this->disableVenueForPeriod($this->planIdOf($entry), $venueId);

        // « au moins 2 séances à Barros » pour une équipe à 1 séance/semaine : ERREUR
        // fail-fast si la contrainte reste dans le jeu validé. Elle nomme un gymnase
        // désactivé ⇒ elle doit sortir (et être annoncée), donc plus d’erreur.
        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setName('SM1 exige 2 séances à Barros');
        $constraint->setScope(ConstraintScope::TEAM);
        $constraint->setScopeTargetId($this->team(sessionsPerWeek: 1));
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['minAtVenueId' => $venueId, 'minAtVenueCount' => 2]);
        $constraint->setCalendarEntryId($entry->getId());
        $constraint->setIsActive(true);
        $this->em->persist($constraint);
        $this->em->flush();

        $data = $this->validateForPeriod($entry);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($data['valid']);
        self::assertSame([], $data['errors'], 'la datée visant le gymnase désactivé est retirée, donc plus rien à valider');
        // P2-9 PR A ajoute le volet capacité : ce test garde son assertion EXACTE sur SES
        // warnings (revue #341 round 2 — `assertContains` avait relâché la garantie « un
        // seul warning », qui est précisément ce que ce test épingle).
        self::assertSame(
            ['« SM1 exige 2 séances à Barros » vise le gymnase Barros, désactivé pour cette période : elle ne sera pas appliquée.'],
            array_values(array_filter($data['warnings'], static fn (string $w): bool => !str_contains($w, 'gymnases'))),
            'exactement UN warning de sélection ; ceux de capacité sont filtrés, ils ont leur propre NR',
        );
    }

    public function testNoWarningWhenTheVenueStaysEnabledForThePeriod(): void
    {
        $venueId = $this->venue('Barros');
        $entry = $this->period(CalendarEntryPeriodType::CLOSURE);
        $this->venueScopedConstraint('SM1 impose Barros', $venueId, ['maxStartTime' => '10:00']);

        $data = $this->validateForPeriod($entry);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($data['valid']);
        self::assertSame([], $data['warnings'], 'sans désactivation, aucune contrainte n’est écartée');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Constraint Test Club');
        $this->club->setSlug('constraint-test-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->club->setFfbbClubCode('CST' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('cst' . $uid . '@test.com');
        $this->user->setFirstName('Cst');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
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
    }

    /** @param array<string, mixed> $config */
    private function constraint(array $config): void
    {
        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setName('rule');
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig($config);
        $constraint->setIsActive(true);
        $this->em->persist($constraint);
        $this->em->flush();
    }

    /** Persist a minimal venue scoped to the test club/season, return its id. */
    private function venue(string $name): string
    {
        $venue = new Venue;
        $venue->setClubId($this->club->getId());
        $venue->setSeasonId($this->season->getId());
        $venue->setName($name);
        $venue->setCanSplit(false);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue->getId();
    }

    /** Un créneau SAISONNIER (schedulePlanId null) — celui que `buildForClubSeason` sérialise. */
    private function trainingSlot(string $venueId, int $dayOfWeek, string $start): void
    {
        $slot = new VenueTrainingSlot;
        $slot->setClubId($this->club->getId());
        $slot->setSeasonId($this->season->getId());
        $slot->setVenueId($venueId);
        $slot->setDayOfWeek($dayOfWeek);
        $slot->setStartTime(new DateTimeImmutable($start));
        $slot->setDurationMinutes(90);
        $slot->setCapacity(1);
        $this->em->persist($slot);
        $this->em->flush();
    }

    /** « Au moins 1 séance dans ce gymnase » pour l'équipe — la contrainte du scénario BCCL. */
    private function minAtVenue(string $teamId, string $venueId): void
    {
        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setName('Au moins 1 à Matéo');
        $constraint->setScope(ConstraintScope::TEAM);
        $constraint->setScopeTargetId($teamId);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['minAtVenueId' => $venueId, 'minAtVenueCount' => 1]);
        $constraint->setIsActive(true);
        $this->em->persist($constraint);
        $this->em->flush();
    }

    /** Une réservation du socle (schedulePlanId null) — un verrou HARD pour le solveur. */
    private function reservation(string $teamId, string $venueId, int $dayOfWeek, string $start): void
    {
        $reservation = new Reservation;
        $reservation->setClubId($this->club->getId());
        $reservation->setSeasonId($this->season->getId());
        $reservation->setTeamId($teamId);
        $reservation->setVenueId($venueId);
        $reservation->setDayOfWeek($dayOfWeek);
        $reservation->setStartTime(new DateTimeImmutable($start));
        $reservation->setDurationMinutes(90);
        $this->em->persist($reservation);
        $this->em->flush();
    }

    /** Un coach MAIN pour l'équipe, retourne son id. */
    private function mainCoach(string $teamId, string $firstName): string
    {
        $coach = new Coach;
        $coach->setClubId($this->club->getId());
        $coach->setSeasonId($this->season->getId());
        $coach->setFirstName($firstName);
        $coach->setLastName('Dupont');
        $this->em->persist($coach);
        $this->em->flush();

        $link = new TeamCoach;
        $link->setClubId($this->club->getId());
        $link->setSeasonId($this->season->getId());
        $link->setTeamId($teamId);
        $link->setCoachId($coach->getId());
        $link->setRole(TeamCoachRole::MAIN);
        $this->em->persist($link);
        $this->em->flush();

        return $coach->getId();
    }

    /** Le geste « ce gymnase ne sert pas cette période » (réglage sparse ancré au plan). */
    private function disableVenueForPeriod(string $schedulePlanId, string $venueId): void
    {
        $override = new VenuePeriodOverride;
        $override->setClubId($this->club->getId());
        $override->setSeasonId($this->season->getId());
        $override->setSchedulePlanId($schedulePlanId);
        $override->setVenueId($venueId);
        $override->setMode(VenuePeriodMode::DISABLED);
        $this->em->persist($override);
        $this->em->flush();
    }

    /**
     * Une permanente HARD TIME qui NOMME un gymnase par son scope (FACILITY).
     *
     * @param array<string, mixed> $config
     */
    private function venueScopedConstraint(string $name, string $venueId, array $config): void
    {
        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setName($name);
        $constraint->setScope(ConstraintScope::FACILITY);
        $constraint->setScopeTargetId($venueId);
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig($config);
        $constraint->setIsActive(true);
        $this->em->persist($constraint);
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    private function validateForPeriod(CalendarEntry $entry): array
    {
        $this->client->loginUser($this->user);
        $this->client->request(
            'POST',
            '/api/constraints/validate',
            [],
            [],
            ['HTTP_X-Club-Id' => $this->club->getId()],
            json_encode(['calendarEntryId' => $entry->getId()], \JSON_THROW_ON_ERROR),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** Persist a minimal team scoped to the test club/season, return its id. */
    private function team(int $sessionsPerWeek): string
    {
        $team = new Team;
        $team->setClubId($this->club->getId());
        $team->setSeasonId($this->season->getId());
        $team->setSportCategoryId($this->club->getId()); // any guid — unused by the gate
        $team->setPriorityTierId(3);
        $team->setName('Test Team');
        $team->setSessionsPerWeek($sessionsPerWeek);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team->getId();
    }

    private function period(CalendarEntryPeriodType $type): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($this->club->getId());
        $entry->setSeasonId($this->season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType($type);
        $entry->setTitle('Period ' . $type->value);
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);
        $this->em->flush();
        // Le geste : en prod le POST crée le plan avec la période. Les réglages y sont
        // ancrés (lot C2), donc sans plan le contrôleur n'a rien à hériter.
        if (\in_array($type, [CalendarEntryPeriodType::CLOSURE, CalendarEntryPeriodType::HOLIDAY], true)) {
            $this->planIdOf($entry);
        }

        return $entry;
    }
}
