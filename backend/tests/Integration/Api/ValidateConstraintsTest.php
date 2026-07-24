<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
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
     * NR #8 (fondateur 2026-07-24) — « on ignore la contrainte, mais on AVERTIT côté
     * récap ». Une contrainte qui vise un gymnase désactivé pour la période n'est pas
     * envoyée au solveur (buildForOverlay la filtre) : le gate pré-solve doit voir le
     * MÊME jeu — sinon il valide ce qui ne partira pas — et le dire au gestionnaire,
     * sans pour autant bloquer la génération.
     */
    public function testAConstraintOnADisabledVenueWarnsWithoutBlocking(): void
    {
        $entry = $this->period(CalendarEntryPeriodType::HOLIDAY);
        $planId = $this->planIdOf($entry);

        $venue = new \App\Entity\Venue;
        $venue->setClubId($this->club->getId());
        $venue->setSeasonId($this->season->getId());
        $venue->setName('Barros');
        $venue->setCanSplit(false);
        $venue->setSource('manual');
        $this->em->persist($venue);

        $forced = new Constraint;
        $forced->setClubId($this->club->getId());
        $forced->setSeasonId($this->season->getId());
        $forced->setName('SM1 impose Barros');
        $forced->setScope(ConstraintScope::TEAM);
        $forced->setFamily(ConstraintFamily::FACILITY);
        $forced->setRuleType(ConstraintRuleType::HARD);
        $forced->setConfig(['forcedVenueId' => $venue->getId()]);
        $forced->setIsActive(true);
        $this->em->persist($forced);

        $override = new \App\Entity\VenuePeriodOverride;
        $override->setClubId($this->club->getId());
        $override->setSeasonId($this->season->getId());
        $override->setSchedulePlanId($planId);
        $override->setVenueId($venue->getId());
        $override->setMode(\App\Enum\VenuePeriodMode::DISABLED);
        $this->em->persist($override);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request(
            'POST',
            '/api/constraints/validate',
            [],
            [],
            ['HTTP_X-Club-Id' => $this->club->getId()],
            json_encode(['calendarEntryId' => $entry->getId()], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['valid'], 'un avertissement ne bloque pas la génération');
        self::assertNotEmpty($data['warnings'], 'le récap doit signaler la contrainte laissée de côté');
        self::assertStringContainsString('Barros', $data['warnings'][0]);
        self::assertStringContainsString('SM1 impose Barros', $data['warnings'][0]);
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

    /** Persist a minimal team scoped to the test club/season, return its id. */
    private function team(int $sessionsPerWeek): string
    {
        $team = new \App\Entity\Team;
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
